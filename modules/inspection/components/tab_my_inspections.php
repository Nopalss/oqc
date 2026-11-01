<!-- ═══════════════════════════════════════════════════════════════ -->
                <!-- TAB 3: RIWAYAT SAYA (INSPEKSI USER LOGIN & RINCIAN LOT)        -->
                <!-- ═══════════════════════════════════════════════════════════════ -->
                <div class="w-full divide-y divide-slate-200">
                    <?php if (empty($mySessions)): ?>
                        <div class="px-4 py-16 text-center text-slate-400">
                            <svg class="w-12 h-12 text-slate-300 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                            </svg>
                            <p class="font-bold text-slate-700 text-sm">Tidak ada riwayat inspeksi Anda pada filter ini.</p>
                            <p class="text-xs text-slate-400 mt-1">Ubah filter periode di atas atau klik tombol <b>"Mulai Inspeksi Baru"</b> untuk mulai melakukan inspeksi.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($mySessions as $idx => $s): 
                            $sid = (int)$s['id'];
                            $displaySessionNum = (int)($s['kanban_session_number'] ?? 1);
                            $sLots = $lotsBySession[$sid] ?? [];
                            $totalLabels = count($sLots);

                            $isClosed = !empty($s['closed_at']);
                            $durationText = $isClosed ? (($s['duration_minutes'] > 0) ? ($s['duration_minutes'] . ' mnt') : '< 1 mnt') : 'Berjalan';
                            $shiftName = getShiftName($s['started_at']);

                            // Kanban Progress & Sisa Qty
                            $isSafetyStock = ($s['inspection_type'] === 'safety_stock');
                            $kbTargetQty = (int)($s['kanban_target_qty'] ?? 0);
                            $kbPassedQty = (int)($s['kanban_passed_qty'] ?? 0);
                            $kbRemainingQty = max(0, $kbTargetQty - $kbPassedQty);
                            $kbProgressPct = ($kbTargetQty > 0) ? min(100, round(($kbPassedQty / $kbTargetQty) * 100)) : 100;
                            $isKbCompleted = ($kbTargetQty > 0 && $kbPassedQty >= $kbTargetQty) || (($s['kanban_status'] ?? '') === 'completed');

                            // Status kelayakan mulai sesi baru untuk item ini (hanya untuk Kanban yang masih memiliki sisa kuota)
                            $canStartNewSession = false;
                            $newSessionUrl = '';
                            $newSessionTitle = '';
                            if (!$isSafetyStock && $s['status'] !== 'in_progress' && !empty($s['kanban_item_id']) && (!$isKbCompleted || $kbRemainingQty > 0)) {
                                $canStartNewSession = true;
                                $newSessionUrl = base_url('modules/inspection/session.php?scan_new=1&kanban_id=' . (int)$s['kanban_item_id']);
                                $newSessionTitle = 'Mulai sesi inspeksi baru untuk Kanban #' . ($s['kanban_no'] ?? '');
                            }

                            $sSubs = $substitutionsBySession[$sid] ?? [];
                            $subsByLotId = [];
                            foreach ($sSubs as $sub) {
                                if (!empty($sub['ng_session_lot_id'])) {
                                    $subsByLotId[(int)$sub['ng_session_lot_id']] = $sub;
                                }
                            }
                            $hasReinspection = false;
                            $isPendingReinspection = false;
                            foreach ($sLots as $lItem) {
                                $lStat = strtolower($lItem['lot_status'] ?? '');
                                if (in_array($lStat, ['replaced', 'sorted', 'reinspection'])) {
                                    $hasReinspection = true;
                                }
                                if ($lStat === 'pending_reinspection') {
                                    $isPendingReinspection = true;
                                    $hasReinspection = true;
                                }
                            }
                            if (!empty($sSubs)) {
                                $hasReinspection = true;
                            }
                        ?>
                            <?php
                                if ($s['status'] === 'passed') {
                                    $sessionCardStyle   = 'border: 1px solid #86efac; border-left: 5px solid #16a34a;';
                                    $sessionHeaderStyle = 'background-color: #f0fdf4; border-bottom: 1px solid #bbf7d0;';
                                    $statusBadgeClass   = 'bg-emerald-600 text-white border border-emerald-700 shadow-xs';
                                } elseif ($s['status'] === 'rejected') {
                                    $sessionCardStyle   = 'border: 1px solid #fca5a5; border-left: 5px solid #dc2626;';
                                    $sessionHeaderStyle = 'background-color: #fff1f2; border-bottom: 1px solid #fecdd3;';
                                    $statusBadgeClass   = 'bg-rose-600 text-white border border-rose-700 shadow-xs';
                                } else {
                                    $sessionCardStyle   = 'border: 1px solid #fcd34d; border-left: 5px solid #d97706;';
                                    $sessionHeaderStyle = 'background-color: #fffbeb; border-bottom: 1px solid #fde68a;';
                                    $statusBadgeClass   = 'bg-amber-500 text-white border border-amber-600 shadow-xs';
                                }
                                $displayPartName = preg_replace('/,([^\s])/', ', $1', $s['display_part_name'] ?? '');
                            ?>
                            <div style="<?= $sessionCardStyle ?>" class="bg-white rounded-xl overflow-hidden shadow-sm mb-4">
                                <!-- 2-Tier Structured Session Header (Enterprise UX Layout) -->
                                <div style="<?= $sessionHeaderStyle ?>" class="transition-colors">
                                    <!-- TIER 1: Identitas Utama & Keputusan Mutu -->
                                    <div style="padding: 12px 18px; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;">
                                        <!-- Sisi Kiri: Hero Subject & Unified Context Badges -->
                                        <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                            <!-- Part Name (Hero Headline) -->
                                            <span style="font-size: 15px; font-weight: 900; color: #0f172a; letter-spacing: -0.01em; margin-right: 6px;" title="<?= htmlspecialchars($displayPartName) ?>">
                                                <?= htmlspecialchars($displayPartName) ?>
                                            </span>

                                            <!-- Part Number Badge -->
                                            <span style="display: inline-flex; align-items: center; height: 26px; padding: 0 10px; border-radius: 6px; font-family: ui-monospace, monospace; font-size: 11.5px; font-weight: 700; background: #f1f5f9; color: #334155; border: 1px solid #cbd5e1;" title="Part Number">
                                                <?= htmlspecialchars($s['part_number']) ?>
                                            </span>

                                            <!-- Badge Kanban / Safety Stock (Warna Terang, Kuat, Solid & Dibedakan Tegas) -->
                                            <?php if ($isSafetyStock): ?>
                                                <!-- SAFETY STOCK: Solid Vivid Purple -->
                                                <span style="display: inline-flex; align-items: center; gap: 6px; height: 26px; padding: 0 11px; border-radius: 6px; font-size: 11.5px; font-weight: 900; background: #7c3aed; color: #ffffff; border: 1px solid #6d28d9; box-shadow: 0 1px 2px rgba(124, 58, 237, 0.25);">
                                                    <svg width="13" height="13" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                                                    <span>SAFETY STOCK</span>
                                                </span>
                                            <?php else: ?>
                                                <!-- KANBAN: Solid Vivid Royal Blue -->
                                                <span style="display: inline-flex; align-items: center; gap: 6px; height: 26px; padding: 0 11px; border-radius: 6px; font-size: 11.5px; font-weight: 900; background: #2563eb; color: #ffffff; border: 1px solid #1d4ed8; box-shadow: 0 1px 2px rgba(37, 99, 235, 0.25);">
                                                    <svg width="13" height="13" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                                                    <span>#<?= htmlspecialchars($s['kanban_no'] ?? '-') ?></span>
                                                </span>
                                            <?php endif; ?>

                                            <!-- Badge Sesi (Urutan Sesi per Kanban, disembunyikan untuk Safety Stock) -->
                                            <?php if (!$isSafetyStock): ?>
                                                <span style="display: inline-flex; align-items: center; gap: 4px; height: 26px; padding: 0 9px; border-radius: 6px; font-size: 11px; font-weight: 700; background: #1e293b; color: #ffffff; border: 1px solid #0f172a;" title="Sesi ke-<?= $displaySessionNum ?> untuk Kanban #<?= htmlspecialchars($s['kanban_no'] ?? '-') ?> (ID Sistem: #<?= $sid ?>)">
                                                    <span style="font-size: 9.5px; text-transform: uppercase; font-weight: 800; color: #94a3b8; letter-spacing: 0.04em;">SESI</span>
                                                    <span style="font-family: ui-monospace, monospace; font-weight: 900; color: #fbbf24;">#<?= $displaySessionNum ?></span>
                                                </span>
                                            <?php endif; ?>

                                            <!-- Tombol Sesi Baru (Mulai Sesi Berikutnya) -->
                                            <?php if ($canStartNewSession): ?>
                                                <a href="<?= $newSessionUrl ?>"
                                                   style="display: inline-flex; align-items: center; gap: 5px; height: 26px; padding: 0 10px; border-radius: 6px; font-size: 11px; font-weight: 800; background: #059669; color: #ffffff; border: 1px solid #047857; text-decoration: none; box-shadow: 0 1px 2px rgba(5, 150, 105, 0.25);"
                                                   class="hover:bg-emerald-700 transition-colors"
                                                   title="<?= htmlspecialchars($newSessionTitle, ENT_QUOTES) ?>">
                                                    <svg width="11" height="11" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M12 4v16m8-8H4"/></svg>
                                                    <span>Mulai Inspeksi</span>
                                                </a>
                                            <?php endif; ?>
                                        </div>

                                        <!-- Sisi Kanan: Status Mutu & Tombol Aksi -->
                                        <div style="display: flex; align-items: center; gap: 8px; flex-shrink: 0;">
                                            <!-- Status Outcome Badge -->
                                            <?php if ($s['status'] === 'passed'): ?>
                                                <span style="display: inline-flex; align-items: center; gap: 5px; height: 28px; padding: 0 11px; border-radius: 6px; font-size: 12px; font-weight: 900; background: #16a34a; color: #ffffff; border: 1px solid #15803d; box-shadow: 0 1px 2px rgba(22, 163, 74, 0.2);">
                                                    <svg width="13" height="13" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                                                    <span>PASSED</span>
                                                </span>
                                            <?php elseif ($s['status'] === 'rejected'): ?>
                                                <span style="display: inline-flex; align-items: center; gap: 5px; height: 28px; padding: 0 11px; border-radius: 6px; font-size: 12px; font-weight: 900; background: #dc2626; color: #ffffff; border: 1px solid #b91c1c; box-shadow: 0 1px 2px rgba(220, 38, 38, 0.2);">
                                                    <svg width="13" height="13" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M6 18L18 6M6 6l12 12"/></svg>
                                                    <span>REJECTED</span>
                                                </span>
                                            <?php else: ?>
                                                <span style="display: inline-flex; align-items: center; gap: 6px; height: 28px; padding: 0 11px; border-radius: 6px; font-size: 12px; font-weight: 900; background: #d97706; color: #ffffff; border: 1px solid #b45309; box-shadow: 0 1px 2px rgba(217, 119, 6, 0.2);">
                                                    <span style="width: 7px; height: 7px; border-radius: 9999px; background: #ffffff;" class="animate-pulse"></span>
                                                    <span>BERJALAN</span>
                                                </span>
                                            <?php endif; ?>

                                            <!-- Re-inspeksi Tag jika ada -->
                                            <?php if ($isPendingReinspection): ?>
                                                <span style="display: inline-flex; align-items: center; gap: 5px; height: 28px; padding: 0 9px; border-radius: 6px; font-size: 11px; font-weight: 800; background: #fef3c7; color: #92400e; border: 1px solid #fcd34d;">
                                                    <span style="width: 6px; height: 6px; border-radius: 9999px; background: #d97706;" class="animate-pulse"></span>
                                                    <span>Perlu Re-inspeksi</span>
                                                </span>
                                            <?php elseif ($hasReinspection): ?>
                                                <span style="display: inline-flex; align-items: center; gap: 5px; height: 28px; padding: 0 9px; border-radius: 6px; font-size: 11px; font-weight: 800; background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe;">
                                                    <svg width="11" height="11" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                                                    <span>Ada Re-inspeksi</span>
                                                </span>
                                            <?php endif; ?>

                                            <!-- Primary Action Button -->
                                            <?php if ($s['status'] === 'in_progress'): ?>
                                                <a href="<?= base_url('modules/inspection/session.php?id=' . $sid) ?>"
                                                   style="display: inline-flex; align-items: center; gap: 5px; height: 28px; padding: 0 12px; border-radius: 6px; font-size: 11px; font-weight: 800; background: #d97706; color: #ffffff; border: 1px solid #b45309; text-decoration: none;"
                                                   class="hover:opacity-95 transition-opacity cursor-pointer">
                                                    <svg width="12" height="12" viewBox="0 0 20 20" fill="currentColor"><path d="M6.3 2.841A1.5 1.5 0 004 4.11V15.89a1.5 1.5 0 002.3 1.269l9.344-5.89a1.5 1.5 0 000-2.538L6.3 2.84z"/></svg>
                                                    <span>Lanjutkan</span>
                                                </a>
                                            <?php else: ?>
                                                <a href="<?= base_url('modules/inspection/session.php?id=' . $sid) ?>"
                                                   style="display: inline-flex; align-items: center; gap: 5px; height: 28px; padding: 0 12px; border-radius: 6px; font-size: 11px; font-weight: 800; background: #4f46e5; color: #ffffff; border: 1px solid #4338ca; text-decoration: none;"
                                                   class="hover:opacity-95 transition-opacity cursor-pointer">
                                                    <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                                                    <span>Buka</span>
                                                </a>
                                                <?php if ($s['status'] === 'rejected'): ?>
                                                    <a href="<?= base_url('modules/inspection/print_rejection.php?session_id=' . $sid) ?>" target="_blank" rel="noopener"
                                                       style="display: inline-flex; align-items: center; gap: 5px; height: 28px; padding: 0 10px; border-radius: 6px; font-size: 11px; font-weight: 800; background: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; text-decoration: none;"
                                                       class="hover:bg-rose-200 transition-colors">
                                                        <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                                                        <span>Cetak Reject</span>
                                                    </a>
                                                <?php endif; ?>
                                            <?php endif; ?>

                                            <!-- Toggle Lot Details -->
                                            <button type="button" onclick="toggleMyDetail(<?= $sid ?>)"
                                                    style="display: inline-flex; align-items: center; gap: 5px; height: 28px; padding: 0 10px; border-radius: 6px; font-size: 11px; font-weight: 800; background: #ffffff; color: #334155; border: 1px solid #cbd5e1; cursor: pointer;"
                                                    class="hover:bg-slate-50 transition-colors">
                                                <span>Lot</span>
                                                <span style="color: #64748b; font-weight: 700;">(<?= $totalLabels ?>)</span>
                                                <svg id="my-chevron-<?= $sid ?>" style="width: 12px; height: 12px; color: #94a3b8; transform: rotate(180deg); transition: transform 0.2s;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7"/></svg>
                                            </button>
                                        </div>
                                    </div>

                                    <!-- TIER 2: Progress Order Kanban & Metrik Sesi (Operational Metadata Ribbon) -->
                                    <div style="background-color: #f8fafc; border-top: 1px solid #e2e8f0; padding: 8px 18px; display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; font-size: 11px;">
                                        <!-- Sisi Kiri: Progress Order Kanban / Safety Stock -->
                                        <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                                            <?php if (!$isSafetyStock && $kbTargetQty > 0): ?>
                                                <!-- Label Progress -->
                                                <div style="display: flex; align-items: center; gap: 6px;">
                                                    <span style="width: 7px; height: 7px; border-radius: 9999px; background: <?= ($isKbCompleted || $kbRemainingQty <= 0) ? '#16a34a' : '#d97706' ?>; display: inline-block;"></span>
                                                    <span style="font-weight: 800; color: <?= ($isKbCompleted || $kbRemainingQty <= 0) ? '#15803d' : '#b45309' ?>; text-transform: uppercase; font-size: 10px; letter-spacing: 0.04em;">Progress:</span>
                                                </div>

                                                <!-- Mini Progress Bar (Guaranteed Width & Height) -->
                                                <div style="width: 120px; height: 8px; background: #e2e8f0; border-radius: 9999px; overflow: hidden; position: relative;" title="<?= $kbProgressPct ?>% Terpenuhi">
                                                    <div style="height: 100%; width: <?= $kbProgressPct ?>%; background: <?= ($isKbCompleted || $kbRemainingQty <= 0) ? '#16a34a' : '#d97706' ?>; border-radius: 9999px; transition: width 0.3s;"></div>
                                                </div>

                                                <!-- Qty Counter -->
                                                <div style="display: inline-flex; align-items: baseline; gap: 4px; font-family: ui-monospace, monospace;">
                                                    <span style="font-weight: 900; color: #0f172a; font-size: 12px;"><?= number_format($kbPassedQty) ?></span>
                                                    <span style="color: #64748b; font-size: 11px;">/ <?= number_format($kbTargetQty) ?> pcs</span>
                                                </div>

                                                <!-- Outcome Badge: Selesai atau Sisa Qty -->
                                                <?php if ($isKbCompleted || $kbRemainingQty <= 0): ?>
                                                    <span style="display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px; border-radius: 6px; font-size: 10.5px; font-weight: 800; background: #16a34a; color: #ffffff;">
                                                        <svg width="11" height="11" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                                                        <span>Tuntas (100%)</span>
                                                    </span>
                                                <?php else: ?>
                                                    <span style="display: inline-flex; align-items: center; gap: 5px; padding: 2px 8px; border-radius: 6px; font-size: 10.5px; font-weight: 800; background: #d97706; color: #ffffff;">
                                                        <span style="width: 6px; height: 6px; border-radius: 9999px; background: #ffffff;" class="animate-pulse"></span>
                                                        <span>Sisa: <?= number_format($kbRemainingQty) ?> pcs (<?= $kbProgressPct ?>%)</span>
                                                    </span>
                                                <?php endif; ?>

                                            <?php elseif ($isSafetyStock): ?>
                                                <!-- Safety Stock Banner -->
                                                <div style="display: inline-flex; align-items: center; gap: 6px; padding: 3px 10px; border-radius: 6px; font-size: 11px; font-weight: 800; background: #7c3aed; color: #ffffff;">
                                                    <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                                                    <span>SAFETY STOCK: Buffer Gudang / Non-Kanban</span>
                                                </div>
                                            <?php else: ?>
                                                <span style="color: #94a3b8; font-style: italic; font-size: 11px;">Tanpa target kuota Kanban</span>
                                            <?php endif; ?>
                                        </div>

                                        <!-- Sisi Kanan: Metrik Sesi, Waktu & Shift -->
                                        <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap; color: #475569;">
                                            <!-- Qty Sesi -->
                                            <div style="display: flex; align-items: center; gap: 5px;">
                                                <span style="font-weight: 700; color: #64748b;">Qty Sesi:</span>
                                                <span style="display: inline-flex; align-items: center; padding: 1px 7px; border-radius: 5px; font-family: ui-monospace, monospace; font-size: 11.5px; font-weight: 800; background: #ffffff; color: #0f172a; border: 1px solid #cbd5e1;">
                                                    <?= number_format($s['total_scanned_qty']) ?> pcs
                                                </span>
                                            </div>

                                            <span style="color: #cbd5e1; font-weight: bold;">•</span>

                                            <!-- Waktu & Tanggal -->
                                            <div style="display: flex; align-items: center; gap: 5px; color: #334155;">
                                                <svg width="12" height="12" style="color: #64748b;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                                <span style="font-weight: 600;"><?= date('d M Y', strtotime($s['started_at'])) ?>,</span>
                                                <span style="font-family: ui-monospace, monospace; font-weight: 800; color: #0f172a;"><?= date('H:i', strtotime($s['started_at'])) ?></span>
                                                <span style="font-size: 9.5px; color: #94a3b8;">WIB</span>
                                            </div>

                                            <!-- Shift -->
                                            <span style="display: inline-flex; align-items: center; padding: 1px 7px; border-radius: 5px; font-size: 10px; font-weight: 800; background: #e0e7ff; color: #3730a3; border: 1px solid #c7d2fe;">
                                                <?= htmlspecialchars($shiftName) ?>
                                            </span>

                                            <?php if ($isClosed): ?>
                                                <span style="color: #cbd5e1; font-weight: bold;">•</span>
                                                <span style="color: #64748b; font-size: 10.5px;" title="Durasi Pemeriksaan Sesi"><?= $durationText ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <!-- Sub-Tabel Rincian Box / Lot (Persis Desain Daily Report Detail) -->
                                <div id="my-detail-<?= $sid ?>" class="px-3.5 py-3 bg-slate-50/40 border-t border-slate-100">
                                    <?php
                                        $totalDisplayAttempts = 0;
                                        foreach ($sLots as $lTmp) {
                                            $lIdTmp = (int)$lTmp['id'];
                                            $subLogTmp = $subsByLotId[$lIdTmp] ?? null;
                                            $lotDefectsTmp = $ngRecordsByLot[$lIdTmp] ?? [];
                                            $isSortTmp = ($subLogTmp && $subLogTmp['action_type'] === 'sort_reinspect');
                                            if (!$isSortTmp) {
                                                foreach ($lotDefectsTmp as $dfTmp) {
                                                    if (!empty($dfTmp['is_sorted'])) { $isSortTmp = true; break; }
                                                }
                                            }
                                            $totalDisplayAttempts += $isSortTmp ? 2 : 1;
                                        }
                                    ?>
                                    <div class="flex items-center justify-between text-[10px] font-black text-slate-500 uppercase tracking-wider mb-2 px-0.5">
                                        <div class="flex items-center gap-1.5">
                                            <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                                            <span>Rincian Box / Lot yang Diperiksa (<?= $totalDisplayAttempts ?> <?= $totalDisplayAttempts > 1 ? 'Pemeriksaan' : 'Box' ?> &bull; <?= number_format($s['total_scanned_qty']) ?> pcs)</span>
                                        </div>
                                        <?php if (!$isSafetyStock && $kbTargetQty > 0): ?>
                                            <div class="text-[10px] font-bold text-slate-600 normal-case hidden sm:block">
                                                Akumulasi Kanban: <span class="font-mono font-black text-slate-800"><?= number_format($kbPassedQty) ?></span> / <span class="font-mono font-bold text-slate-600"><?= number_format($kbTargetQty) ?> pcs</span>
                                                <?php if ($kbRemainingQty > 0): ?>
                                                    &bull; <span class="text-rose-600 font-bold">Kurang <?= number_format($kbRemainingQty) ?> pcs</span>
                                                <?php else: ?>
                                                    &bull; <span class="text-emerald-600 font-bold">Target Terpenuhi</span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <?php if (empty($sLots)): ?>
                                        <div class="py-5 text-center text-slate-400 text-xs italic bg-white rounded-lg border border-slate-200">
                                            Belum ada label/lot yang discan pada sesi ini.
                                        </div>
                                    <?php else: ?>
                                        <div style="overflow-x: auto; border: 1px solid #f1f5f9; border-radius: 8px; background: #ffffff;">
                                            <table style="width: 100%; border-collapse: collapse; font-size: 11px;">
                                                <thead style="background: #f8fafc; color: #64748b; font-weight: 700; border-bottom: 1px solid #e2e8f0;">
                                                    <tr>
                                                        <th style="padding: 6px 10px; width: 30px; text-align: center;">#</th>
                                                        <th style="padding: 6px 10px; min-width: 130px; text-align: left;">Ref Number</th>
                                                        <th style="padding: 6px 10px; min-width: 120px; text-align: left;">Lot Number</th>
                                                        <th style="padding: 6px 10px; text-align: right; width: 85px;">Qty Box</th>
                                                        <th style="padding: 6px 10px; text-align: center; width: 95px;">Sampel (pcs)</th>
                                                        <th style="padding: 6px 10px; text-align: center; width: 75px;">NG</th>
                                                        <th style="padding: 6px 10px; text-align: center; width: 95px;">Status Lot</th>
                                                        <th style="padding: 6px 10px; min-width: 100px; text-align: left;">Waktu Scan</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php 
                                                    $lotRowNo = 1;
                                                    foreach ($sLots as $lItem): 
                                                        $lId = (int)$lItem['id'];
                                                        $subLog = $subsByLotId[$lId] ?? null;
                                                        $lotDefects = $ngRecordsByLot[$lId] ?? [];
                                                        $isSortReinspect = ($subLog && $subLog['action_type'] === 'sort_reinspect');
                                                        if (!$isSortReinspect) {
                                                            foreach ($lotDefects as $df) {
                                                                if (!empty($df['is_sorted'])) {
                                                                    $isSortReinspect = true;
                                                                    break;
                                                                }
                                                            }
                                                        }

                                                        $isSafetyStockLot = (($lItem['lot_result'] ?? '') === 'skipped') 
                                                            || (!empty($lItem['remarks']) && strpos($lItem['remarks'], 'Safety Stock') !== false);

                                                        if ($isSafetyStockLot) {
                                                            $sampleSize = 0;
                                                            $rejectNumber = 0;
                                                        } else {
                                                            $lotAql = getLotAqlFallback($lItem['qty'], $pdo);
                                                            $sampleSize = ($lItem['sample_size'] > 0) ? (int)$lItem['sample_size'] : (int)$lotAql['sample_size'];
                                                            $rejectNumber = (int)$lotAql['reject_number'];
                                                        }

                                                        if ($isSortReinspect):
                                                            $attempt1Defects = [];
                                                            $attempt2Defects = [];
                                                            foreach ($lotDefects as $df) {
                                                                if (!empty($df['is_sorted'])) {
                                                                    $attempt1Defects[] = $df;
                                                                } else {
                                                                    $attempt2Defects[] = $df;
                                                                }
                                                            }
                                                            if (empty($attempt1Defects) && !empty($lotDefects)) {
                                                                if ($lItem['lot_result'] === 'passed') {
                                                                    $attempt1Defects = $lotDefects;
                                                                    $attempt2Defects = [];
                                                                } else {
                                                                    $attempt1Defects = $lotDefects;
                                                                    $attempt2Defects = $lotDefects;
                                                                }
                                                            }

                                                            $att1NgCount = 0;
                                                            foreach ($attempt1Defects as $d) { $att1NgCount += (int)($d['qty_ng'] ?? 1); }
                                                            if ($att1NgCount === 0) $att1NgCount = 1;

                                                            $att2NgCount = 0;
                                                            foreach ($attempt2Defects as $d) { $att2NgCount += (int)($d['qty_ng'] ?? 1); }

                                                            $att1Time = !empty($attempt1Defects[0]['created_at']) ? $attempt1Defects[0]['created_at'] : $lItem['created_at'];
                                                            $att2Time = !empty($subLog['created_at']) ? $subLog['created_at'] : ($lItem['action_noted_at'] ?: $lItem['closed_at'] ?: $lItem['created_at']);
                                                            $isAtt2Passed = ($lItem['lot_result'] === 'passed');
                                                    ?>
                                                            <!-- Baris 1: Uji Awal (NG) -->
                                                            <tr style="border-bottom: 1px dashed #fca5a5; background: #fffbfb;">
                                                                <td style="padding: 6px 10px; text-align: center; color: #94a3b8; font-weight: 700;"><?= $lotRowNo++ ?></td>
                                                                <td style="padding: 6px 10px; font-family: monospace; font-weight: 800; color: #7c3aed;">
                                                                    <a href="<?= base_url('modules/inspection/session.php?id=' . $sid) ?>" class="hover:underline" title="Buka Sesi Inspeksi">
                                                                        <?= htmlspecialchars($lItem['ref_number'] ?: '-') ?>
                                                                    </a>
                                                                    <span style="display: inline-block; margin-left: 5px; font-family: sans-serif; font-size: 9px; font-weight: 800; background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; padding: 1px 5px; border-radius: 4px; vertical-align: middle;">
                                                                        UJI AWAL (NG)
                                                                    </span>
                                                                </td>
                                                                <td style="padding: 6px 10px; font-family: monospace; color: #334155;">
                                                                    <?= htmlspecialchars($lItem['lot_number'] ?: '-') ?>
                                                                </td>
                                                                <td style="padding: 6px 10px; text-align: right; font-family: monospace; font-weight: 700; color: #0f172a;">
                                                                    <?= number_format($lItem['qty']) ?> pcs
                                                                </td>
                                                                <td style="padding: 6px 10px; text-align: center; font-family: monospace; font-weight: 700; color: #2563eb;">
                                                                    <?= $sampleSize ?> pcs
                                                                </td>
                                                                <td style="padding: 6px 10px; text-align: center;">
                                                                    <span style="font-family: monospace; font-size: 11px; font-weight: 800; padding: 2px 7px; border-radius: 4px; background: #fef2f2; color: #dc2626; border: 1px solid #fecaca;">
                                                                        <?= $att1NgCount ?> / <?= $rejectNumber ?>
                                                                    </span>
                                                                </td>
                                                                <td style="padding: 6px 10px; text-align: center; vertical-align: middle;">
                                                                    <span style="font-family: sans-serif; font-size: 9px; font-weight: 800; background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; padding: 2px 7px; border-radius: 4px; display: inline-flex; align-items: center; justify-content: center; gap: 4px;">
                                                                        <svg width="10" height="10" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M6 18L18 6M6 6l12 12"/></svg>
                                                                        <span>NG / REJECTED</span>
                                                                    </span>
                                                                </td>
                                                                <td style="padding: 6px 10px; color: #64748b; font-family: monospace; font-size: 10.5px;">
                                                                    <div><?= date('d/m/Y', strtotime($att1Time)) ?></div>
                                                                    <div style="font-weight: 700; color: #334155;"><?= date('H:i \W\I\B', strtotime($att1Time)) ?></div>
                                                                </td>
                                                            </tr>

                                                            <!-- Baris Temuan Cacat pada Pemeriksaan Awal -->
                                                            <tr style="background: #fff5f5; border-bottom: 2px solid #fecaca;">
                                                                <td colspan="8" style="padding: 6px 10px 8px 12px;">
                                                                    <div style="display: flex; flex-direction: column; gap: 4px;">
                                                                        <div style="font-size: 10px; font-weight: 800; color: #991b1b; text-transform: uppercase; letter-spacing: 0.5px; display: flex; align-items: center; gap: 4px;">
                                                                            <svg style="width: 12px; height: 12px; color: #dc2626;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                                                                            <span>TEMUAN CACAT PADA PEMERIKSAAN AWAL (<?= count($attempt1Defects) ?> DEFECT):</span>
                                                                        </div>
                                                                        <?php foreach ($attempt1Defects as $df): ?>
                                                                            <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px; font-size: 11px; background: #ffffff; padding: 4px 8px; border-radius: 5px; border: 1px solid #fee2e2; flex-wrap: wrap;">
                                                                                <div style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                                                                                    <span style="font-weight: 800; color: #dc2626;">• <?= htmlspecialchars($df['defect_name'] ?? 'Defect') ?>:</span>
                                                                                    <span style="font-family: monospace; font-weight: 800; color: #b91c1c; background: #fee2e2; padding: 1px 6px; border-radius: 3px;">
                                                                                        <?= (int)($df['qty_ng'] ?? 1) ?> pcs
                                                                                    </span>
                                                                                    <?php if (!empty($df['remark'])): ?>
                                                                                        <span style="color: #64748b; font-style: italic; font-size: 10px;">(<?= htmlspecialchars($df['remark']) ?>)</span>
                                                                                    <?php endif; ?>
                                                                                </div>
                                                                                <div style="display: flex; align-items: center; gap: 10px; font-size: 10px; color: #475569;">
                                                                                    <span>
                                                                                        <span style="color: #64748b;">Inspector:</span>
                                                                                        <strong style="color: #0f172a;"><?= htmlspecialchars($s['inspector_name'] ?? 'Inspector') ?></strong>
                                                                                    </span>
                                                                                    <span style="background: #f1f5f9; color: #334155; padding: 2px 7px; border-radius: 4px; font-family: monospace; font-weight: 700; border: 1px solid #e2e8f0; font-size: 10px; display: inline-flex; align-items: center; gap: 4px;">
                                                                                        <span><?= !empty($df['created_at']) ? date('d/m/Y', strtotime($df['created_at'])) : date('d/m/Y', strtotime($att1Time)) ?></span>
                                                                                        <span style="color: #94a3b8;">•</span>
                                                                                        <span><?= !empty($df['created_at']) ? date('H:i \W\I\B', strtotime($df['created_at'])) : date('H:i \W\I\B', strtotime($att1Time)) ?></span>
                                                                                    </span>
                                                                                </div>
                                                                            </div>
                                                                        <?php endforeach; ?>
                                                                    </div>
                                                                </td>
                                                            </tr>

                                                            <!-- Baris 2: Re-inspeksi -->
                                                            <tr style="border-bottom: <?= $isAtt2Passed ? '1px dashed #86efac' : '1px dashed #fca5a5' ?>; background: <?= $isAtt2Passed ? '#f0fdf4' : '#fffbfb' ?>;">
                                                                <td style="padding: 6px 10px; text-align: center; color: #94a3b8; font-weight: 700;"><?= $lotRowNo++ ?></td>
                                                                <td style="padding: 6px 10px; font-family: monospace; font-weight: 800; color: #7c3aed;">
                                                                    <a href="<?= base_url('modules/inspection/session.php?id=' . $sid) ?>" class="hover:underline" title="Buka Sesi Inspeksi">
                                                                        <?= htmlspecialchars($lItem['ref_number'] ?: '-') ?>
                                                                    </a>
                                                                    <span style="display: inline-block; margin-left: 5px; font-family: sans-serif; font-size: 9px; font-weight: 800; <?= $isAtt2Passed ? 'background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0;' : 'background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca;' ?> padding: 1px 5px; border-radius: 4px; vertical-align: middle;">
                                                                        <?= $isAtt2Passed ? 'RE-INSPEKSI' : 'RE-INSPEKSI (NG)' ?>
                                                                    </span>
                                                                </td>
                                                                <td style="padding: 6px 10px; font-family: monospace; color: #334155;">
                                                                    <?= htmlspecialchars($lItem['lot_number'] ?: '-') ?>
                                                                </td>
                                                                <td style="padding: 6px 10px; text-align: right; font-family: monospace; font-weight: 700; color: #0f172a;">
                                                                    <?= number_format($lItem['qty']) ?> pcs
                                                                </td>
                                                                <td style="padding: 6px 10px; text-align: center; font-family: monospace; font-weight: 700; color: #2563eb;">
                                                                    <?= $sampleSize ?> pcs
                                                                </td>
                                                                <td style="padding: 6px 10px; text-align: center;">
                                                                    <span style="font-family: monospace; font-size: 11px; font-weight: 800; padding: 2px 7px; border-radius: 4px; <?= ($att2NgCount > 0) ? 'background: #fef2f2; color: #dc2626; border: 1px solid #fecaca;' : 'background: #f0fdf4; color: #16a34a; border: 1px solid #dcfce7;' ?>">
                                                                        <?= $att2NgCount ?> / <?= $rejectNumber ?>
                                                                    </span>
                                                                </td>
                                                                <td style="padding: 6px 10px; text-align: center; vertical-align: middle;">
                                                                    <?php if ($isAtt2Passed): ?>
                                                                        <span style="font-family: sans-serif; font-size: 9px; font-weight: 800; background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; padding: 2px 7px; border-radius: 4px; display: inline-flex; align-items: center; justify-content: center; gap: 4px;">
                                                                            <svg width="10" height="10" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                                                                            <span>PASSED</span>
                                                                        </span>
                                                                    <?php else: ?>
                                                                        <span style="font-family: sans-serif; font-size: 9px; font-weight: 800; background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; padding: 2px 7px; border-radius: 4px; display: inline-flex; align-items: center; justify-content: center; gap: 4px;">
                                                                            <svg width="10" height="10" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M6 18L18 6M6 6l12 12"/></svg>
                                                                            <span>REJECTED</span>
                                                                        </span>
                                                                    <?php endif; ?>
                                                                </td>
                                                                <td style="padding: 6px 10px; color: #64748b; font-family: monospace; font-size: 10.5px;">
                                                                    <div><?= date('d/m/Y', strtotime($att2Time)) ?></div>
                                                                    <div style="font-weight: 700; color: #334155;"><?= date('H:i \W\I\B', strtotime($att2Time)) ?></div>
                                                                </td>
                                                            </tr>

                                                            <!-- Baris Hasil Verifikasi & Re-inspeksi -->
                                                            <tr style="background: <?= $isAtt2Passed ? '#f0fdf4' : '#fff1f2' ?>; border-bottom: 2px solid <?= $isAtt2Passed ? '#bbf7d0' : '#fecdd3' ?>;">
                                                                <td colspan="8" style="padding: 6px 10px 8px 12px;">
                                                                    <div style="display: flex; flex-direction: column; gap: 4px;">
                                                                        <div style="font-size: 10px; font-weight: 800; color: <?= $isAtt2Passed ? '#166534' : '#be123c' ?>; text-transform: uppercase; letter-spacing: 0.5px; display: flex; align-items: center; gap: 4px;">
                                                                            <?php if ($isAtt2Passed): ?>
                                                                                <svg style="width: 13px; height: 13px; color: #16a34a;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                                                                <span>HASIL VERIFIKASI & RE-INSPEKSI: NOL CACAT / DEFECT (PASSED)</span>
                                                                            <?php else: ?>
                                                                                <svg style="width: 13px; height: 13px; color: #e11d48;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                                                                <span>HASIL VERIFIKASI & RE-INSPEKSI: TETAP DITEMUKAN CACAT / NG (REJECTED)</span>
                                                                            <?php endif; ?>
                                                                        </div>
                                                                        <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px; font-size: 11px; background: #ffffff; padding: 5px 10px; border-radius: 5px; border: 1px solid <?= $isAtt2Passed ? '#dcfce7' : '#fecdd3' ?>; flex-wrap: wrap;">
                                                                            <div style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                                                                                <span style="font-weight: 800; color: <?= $isAtt2Passed ? '#15803d' : '#be123c' ?>;">Tindakan:</span>
                                                                                <span style="color: #1e293b;">
                                                                                    <?php 
                                                                                        $actNotes = $subLog['notes'] ?? ($attempt1Defects[0]['sort_notes'] ?? '');
                                                                                        if (empty($actNotes)) {
                                                                                            $actNotes = $isAtt2Passed 
                                                                                                ? "Lot #{$lItem['lot_number']} (Ref: {$lItem['ref_number']}) disortir part NG-nya dan diuji ulang sampel fisik."
                                                                                                : "Lot #{$lItem['lot_number']} (Ref: {$lItem['ref_number']}) disortir part NG-nya dan diuji ulang fisik, namun tetap ditemukan cacat.";
                                                                                        }
                                                                                        echo htmlspecialchars($actNotes);
                                                                                    ?>
                                                                                </span>
                                                                            </div>
                                                                            <div style="display: flex; align-items: center; gap: 10px; font-size: 10px; color: #475569;">
                                                                                <span>
                                                                                    <span style="color: #64748b;">Inspector:</span>
                                                                                    <strong style="color: #0f172a;"><?= htmlspecialchars($subLog['actioned_by_name'] ?? ($s['inspector_name'] ?? 'Admin')) ?></strong>
                                                                                </span>
                                                                                <span style="background: #f1f5f9; color: #334155; padding: 2px 7px; border-radius: 4px; font-family: monospace; font-weight: 700; border: 1px solid #e2e8f0; font-size: 10px; display: inline-flex; align-items: center; gap: 4px;">
                                                                                    <span><?= date('d/m/Y', strtotime($att2Time)) ?></span>
                                                                                    <span style="color: #94a3b8;">•</span>
                                                                                    <span><?= date('H:i \W\I\B', strtotime($att2Time)) ?></span>
                                                                                </span>
                                                                            </div>
                                                                        </div>
                                                                    </div>
                                                                </td>
                                                            </tr>

                                                    <?php else: 
                                                            $hasDefects = !empty($lotDefects);
                                                            $ngCount = (int)($lItem['ng_count'] ?? count($lotDefects));
                                                            $isReplaced = ($lItem['lot_status'] === 'replaced' || !empty($lItem['replaced_by_lot_id']));
                                                            $isReplacementBox = (isset($lItem['remarks']) && strpos($lItem['remarks'], 'Box Pengganti') !== false);
                                                    ?>
                                                            <tr style="border-bottom: <?= $hasDefects ? '1px dashed #fca5a5' : '1px solid #f8fafc' ?>; background: <?= $hasDefects ? '#fffbfb' : 'transparent' ?>;">
                                                                <td style="padding: 6px 10px; text-align: center; color: #94a3b8; font-weight: 700;"><?= $lotRowNo++ ?></td>
                                                                <td style="padding: 6px 10px; font-family: monospace; font-weight: 800; color: #7c3aed;">
                                                                    <a href="<?= base_url('modules/inspection/session.php?id=' . $sid) ?>" class="hover:underline" title="Buka Sesi Inspeksi">
                                                                        <?= htmlspecialchars($lItem['ref_number'] ?: '-') ?>
                                                                    </a>
                                                                    <?php if ($isReplacementBox): ?>
                                                                        <span style="display: inline-block; margin-left: 5px; font-family: sans-serif; font-size: 9px; font-weight: 800; background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; padding: 1px 5px; border-radius: 4px; vertical-align: middle;">
                                                                            BOX PENGGANTI
                                                                        </span>
                                                                    <?php endif; ?>
                                                                </td>
                                                                <td style="padding: 6px 10px; font-family: monospace; color: #334155;">
                                                                    <?= htmlspecialchars($lItem['lot_number'] ?: '-') ?>
                                                                </td>
                                                                <td style="padding: 6px 10px; text-align: right; font-family: monospace; font-weight: 700; color: #0f172a;">
                                                                    <?= number_format($lItem['qty']) ?> pcs
                                                                </td>
                                                                <td style="padding: 6px 10px; text-align: center; font-family: monospace; font-weight: 700; color: #2563eb;">
                                                                    <?php if (!empty($isSafetyStockLot)): ?>
                                                                        <span class="text-slate-600 font-bold">0 pcs</span>
                                                                        <div style="font-size: 8.5px; font-weight: 800; color: #059669; line-height: 1; margin-top: 1px;">Lolos SS</div>
                                                                    <?php else: ?>
                                                                        <?= $sampleSize ?> pcs
                                                                    <?php endif; ?>
                                                                </td>
                                                                <td style="padding: 6px 10px; text-align: center;">
                                                                    <?php if (!empty($isSafetyStockLot)): ?>
                                                                        <span style="font-family: monospace; font-size: 11px; font-weight: 800; padding: 2px 7px; border-radius: 4px; background: #ecfdf5; color: #059669; border: 1px solid #a7f3d0;" title="Lolos pengujian fisik di Safety Stock gudang">
                                                                            0 / 0
                                                                        </span>
                                                                    <?php else: ?>
                                                                        <span style="font-family: monospace; font-size: 11px; font-weight: 800; padding: 2px 7px; border-radius: 4px; <?= $ngCount > 0 ? 'background: #fef2f2; color: #dc2626; border: 1px solid #fecaca;' : 'background: #f0fdf4; color: #16a34a; border: 1px solid #dcfce7;' ?>">
                                                                            <?= $ngCount ?> / <?= $rejectNumber ?>
                                                                        </span>
                                                                    <?php endif; ?>
                                                                </td>
                                                                <td style="padding: 6px 10px; text-align: center; vertical-align: middle;">
                                                                    <?php
    $lotResultStatus = $lItem['lot_result'] ?? 'in_progress';
    $lotStatusFlag = $lItem['lot_status'] ?? '';
    if ($isReplacementBox):
?>
                                                                         <span style="display: inline-block; padding: 2px 7px; border-radius: 4px; font-family: sans-serif; font-size: 9px; font-weight: 800; background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe;">
                                                                            PENGGANTI
                                                                        </span>
                                                                    <?php elseif (!empty($isSafetyStockLot) || $lotResultStatus === 'skipped'): ?>
                                                                        <span style="display: inline-flex; align-items: center; justify-content: center; gap: 4px; padding: 2px 8px; border-radius: 4px; font-family: sans-serif; font-size: 9px; font-weight: 800; background: #059669; color: #ffffff; box-shadow: 0 1px 2px rgba(5, 150, 105, 0.35);" title="Box dialokasikan dari Safety Stock yang sudah lolos uji fisik gudang">
                                                                            <svg width="10" height="10" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                                                                            <span>PASSED (SAFETY STOCK)</span>
                                                                        </span>
                                                                    <?php elseif ($lotResultStatus === 'passed'): ?>
                                                                        <span style="display: inline-flex; align-items: center; justify-content: center; gap: 4px; padding: 2px 8px; border-radius: 4px; font-family: sans-serif; font-size: 9px; font-weight: 800; background: #16a34a; color: #ffffff; box-shadow: 0 1px 2px rgba(220, 38, 38, 0.35); background-color: #16a34a;">
                                                                            <svg width="10" height="10" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                                                                            <span>PASSED</span>
                                                                        </span>
                                                                    <?php elseif ($lotResultStatus === 'rejected' || $lotStatusFlag === 'replaced' || !empty($isReplaced)): ?>
                                                                        <span style="display: inline-flex; align-items: center; justify-content: center; gap: 4px; padding: 2px 8px; border-radius: 4px; font-family: sans-serif; font-size: 9px; font-weight: 800; background: #dc2626; color: #ffffff; box-shadow: 0 1px 2px rgba(220, 38, 38, 0.35);">
                                                                            <svg width="10" height="10" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M6 18L18 6M6 6l12 12"/></svg>
                                                                            <span>REJECTED</span>
                                                                        </span>
                                                                    <?php else: ?>
                                                                        <span style="display: inline-flex; align-items: center; justify-content: center; gap: 3.5px; padding: 2px 8px; border-radius: 4px; font-family: sans-serif; font-size: 9px; font-weight: 800; background: #d97706; color: #ffffff; box-shadow: 0 1px 2px rgba(217, 119, 6, 0.35);">
                                                                            <span style="width: 5px; height: 5px; border-radius: 9999px; background-color: #ffffff; display: inline-block;" class="animate-pulse"></span>
                                                                            <span>BERJALAN</span>
                                                                        </span>
                                                                    <?php endif; ?>
                                                                </td>
                                                                <td style="padding: 6px 10px; color: #64748b; font-family: monospace; font-size: 10.5px;">
                                                                    <div><?= date('d/m/Y', strtotime($lItem['created_at'])) ?></div>
                                                                    <div style="font-weight: 700; color: #334155;"><?= date('H:i \W\I\B', strtotime($lItem['created_at'])) ?></div>
                                                                </td>
                                                            </tr>
                                                            <?php if ($hasDefects): ?>
                                                                <tr style="background: #fff5f5; border-bottom: 2px solid #fecaca;">
                                                                    <td colspan="8" style="padding: 6px 10px 8px 12px;">
                                                                        <div style="display: flex; flex-direction: column; gap: 4px;">
                                                                            <div style="font-size: 10px; font-weight: 800; color: #991b1b; text-transform: uppercase; letter-spacing: 0.5px; display: flex; align-items: center; gap: 4px;">
                                                                                <svg style="width: 12px; height: 12px; color: #dc2626;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                                                                                <span>TEMUAN CACAT PADA PEMERIKSAAN AWAL (<?= count($lotDefects) ?> DEFECT):</span>
                                                                            </div>
                                                                            <?php foreach ($lotDefects as $df): ?>
                                                                                <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px; font-size: 11px; background: #ffffff; padding: 4px 8px; border-radius: 5px; border: 1px solid #fee2e2; flex-wrap: wrap;">
                                                                                    <div style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                                                                                        <span style="font-weight: 800; color: #dc2626;">• <?= htmlspecialchars($df['defect_name'] ?? 'Defect') ?>:</span>
                                                                                        <span style="font-family: monospace; font-weight: 800; color: #b91c1c; background: #fee2e2; padding: 1px 6px; border-radius: 3px;">
                                                                                            <?= (int)($df['qty_ng'] ?? 1) ?> pcs
                                                                                        </span>
                                                                                        <?php if (!empty($df['remark'])): ?>
                                                                                            <span style="color: #64748b; font-style: italic; font-size: 10px;">(<?= htmlspecialchars($df['remark']) ?>)</span>
                                                                                        <?php endif; ?>
                                                                                    </div>
                                                                                    <div style="display: flex; align-items: center; gap: 10px; font-size: 10px; color: #475569;">
                                                                                        <span>
                                                                                            <span style="color: #64748b;">Inspector:</span>
                                                                                            <strong style="color: #0f172a;"><?= htmlspecialchars($s['inspector_name'] ?? 'Inspector') ?></strong>
                                                                                        </span>
                                                                                        <span style="background: #f1f5f9; color: #334155; padding: 2px 7px; border-radius: 4px; font-family: monospace; font-weight: 700; border: 1px solid #e2e8f0; font-size: 10px; display: inline-flex; align-items: center; gap: 4px;">
                                                                                            <span><?= !empty($df['created_at']) ? date('d/m/Y', strtotime($df['created_at'])) : date('d/m/Y', strtotime($lItem['created_at'])) ?></span>
                                                                                            <span style="color: #94a3b8;">•</span>
                                                                                            <span><?= !empty($df['created_at']) ? date('H:i \W\I\B', strtotime($df['created_at'])) : date('H:i \W\I\B', strtotime($lItem['created_at'])) ?></span>
                                                                                        </span>
                                                                                    </div>
                                                                                </div>
                                                                            <?php endforeach; ?>
                                                                        </div>
                                                                    </td>
                                                                </tr>
                                                            <?php endif; ?>
                                                    <?php endif; ?>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
