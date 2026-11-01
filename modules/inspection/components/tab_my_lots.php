<!-- ═══════════════════════════════════════════════════════════════ -->
                <!-- TABEL RINCIAN PER LOT SAYA (FLAT TABLE / SHEET 1 VIEW)         -->
                <!-- ═══════════════════════════════════════════════════════════════ -->
                <div class="w-full" style="min-width: 0; max-width: 100%;">
                    <div class="px-4 py-3 bg-slate-50 border-b border-slate-200 flex items-center justify-between flex-wrap gap-2">
                        <div class="flex items-center gap-2.5">
                            <span class="w-2.5 h-2.5 rounded-full bg-sky-500 animate-pulse"></span>
                            <div>
                                <h3 class="text-xs font-black text-slate-800 uppercase tracking-wider">
                                    Riwayat Inspeksi Lot Saya (Cek Fisik)
                                </h3>
                                <p class="text-[10.5px] text-slate-500 hidden sm:block mt-0.5">
                                    Daftar seluruh lot / box hasil pemeriksaan fisik aktual oleh Anda
                                </p>
                            </div>
                        </div>
                        <div class="flex items-center gap-2 flex-wrap">
                            <!-- Navigasi Cepat Scroll Horizontal Kiri & Kanan -->
                            <div class="inline-flex items-center rounded-lg border border-slate-300/80 bg-white p-0.5 shadow-2xs">
                                <button type="button" 
                                        onclick="scrollMyLotsTable(-350)" 
                                        class="px-2.5 py-1 text-[11px] font-extrabold text-slate-700 hover:text-sky-700 hover:bg-sky-50 rounded transition-all inline-flex items-center gap-1 cursor-pointer select-none" 
                                        title="Geser tabel ke kiri">
                                    <svg class="w-3.5 h-3.5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7"/></svg>
                                    <span>Scroll Kiri</span>
                                </button>
                                <div class="h-3.5 w-px bg-slate-200"></div>
                                <button type="button" 
                                        onclick="scrollMyLotsTable(350)" 
                                        class="px-2.5 py-1 text-[11px] font-extrabold text-slate-700 hover:text-sky-700 hover:bg-sky-50 rounded transition-all inline-flex items-center gap-1 cursor-pointer select-none" 
                                        title="Geser tabel ke kanan">
                                    <span>Scroll Kanan</span>
                                    <svg class="w-3.5 h-3.5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg>
                                </button>
                            </div>

                            <span class="text-[11px] font-extrabold text-sky-800 bg-sky-100 border border-sky-200 px-2.5 py-1 rounded-full whitespace-nowrap">
                                <?= number_format($totalItems) ?> Lot Fisik
                            </span>
                        </div>
                    </div>

                    <div id="my-lots-table-wrapper" class="overflow-x-auto w-full custom-scrollbar" style="width: 100%; max-width: 100%; -webkit-overflow-scrolling: touch;">
                        <table class="w-full text-left text-xs text-slate-700 table-auto" style="width: 100%; min-width: 1560px; border-collapse: collapse;">
                            <thead class="bg-slate-900 text-white font-bold text-[11px] uppercase tracking-wider select-none">
                                <tr>
                                    <th style="padding: 10px 10px; width: 45px; text-align: center;">#</th>
                                    <th style="padding: 10px 10px; text-align: center; width: 140px;">Waktu</th>
                                    <th style="padding: 10px 10px; text-align: center; width: 115px;">Jenis</th>
                                    <th style="padding: 10px 10px; width: 130px;">Part Code</th>
                                    <th style="padding: 10px 10px; min-width: 160px;">Part Name</th>
                                    <th style="padding: 10px 10px; text-align: center; width: 95px;">Model</th>
                                    <th style="padding: 10px 10px; width: 125px;">Lot No</th>
                                    <th style="padding: 10px 10px; width: 115px;">Ref No</th>
                                    <th style="padding: 10px 10px; text-align: right; width: 95px;">QTY Lot</th>
                                    <th style="padding: 10px 10px; text-align: center; width: 105px;">Sampling</th>
                                    <th style="padding: 10px 10px; text-align: center; width: 70px;">NG</th>
                                    <th style="padding: 10px 10px; min-width: 180px;">Defect Temuan</th>
                                    <th style="padding: 10px 10px; text-align: center; width: 130px;">Status Lot</th>
                                    <th style="padding: 10px 10px; text-align: center; width: 75px;">Sesi</th>
                                    <th style="padding: 10px 10px; text-align: center; width: 85px;">Shift</th>
                                    <th style="padding: 10px 10px; width: 130px;">Inspektur</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 text-[11px]">
                                <?php if (empty($myLots)): ?>
                                    <tr>
                                        <td colspan="16" class="py-14 text-center text-slate-400 bg-white">
                                            <div class="flex flex-col items-center justify-center gap-2">
                                                <svg class="w-12 h-12 text-slate-300 stroke-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
                                                </svg>
                                                <span class="font-bold text-slate-600 text-sm">Tidak ada rincian lot yang sesuai filter</span>
                                                <span class="text-xs text-slate-400 max-w-sm">Silakan ubah rentang tanggal (Hari Ini, Pekan Ini, Bulan Ini, atau Semua) atau sesuaikan kata kunci pencarian.</span>
                                            </div>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php 
                                    $rowNum = $offset;
                                    foreach ($myLots as $idx => $rLot): 
                                        $rowNum++;
                                        $hasNg = ((int)($rLot['ng_count'] ?? 0) > 0 || (int)($rLot['total_ng'] ?? 0) > 0 || !empty($rLot['defects']));
                                        $isEven = ($idx % 2 === 0);
                                        $shiftName = getShiftName($rLot['scanned_at'] ?? $rLot['session_started_at']);
                                        $isReinspectLot = !empty($rLot['is_reinspected_lot']) || !empty($rLot['is_reinspection']) || in_array($rLot['lot_status'] ?? '', ['reinspected', 'replaced', 'ng_quarantine']);
                                        $isInitialAttempt = !empty($rLot['is_initial_attempt']) || (($rLot['attempt_type'] ?? '') === 'initial');
                                        
                                        // Build Defect Summary Badges (Matching daily_report/detail.php)
                                        $defList = !empty($rLot['defects']) ? $rLot['defects'] : [];
                                        if (!empty($defList)) {
                                            $defParts = [];
                                            foreach ($defList as $df) {
                                                $defParts[] = '<span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-bold bg-rose-50 text-rose-700 border border-rose-200/80">' . htmlspecialchars($df['defect_name']) . ' <b class="text-rose-900 font-extrabold">(' . (int)($df['qty_ng'] ?? 1) . ' pcs)</b></span>';
                                            }
                                            $defectHtml = implode(' ', $defParts);
                                        } elseif (!empty($rLot['defect_summary']) && $rLot['defect_summary'] !== '-') {
                                            $defectHtml = '<span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-bold bg-rose-50 text-rose-700 border border-rose-200/80">' . htmlspecialchars($rLot['defect_summary']) . '</span>';
                                        } else {
                                            $defectHtml = $hasNg 
                                                ? '<span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-bold bg-rose-50 text-rose-700 border border-rose-200">Temuan Cacat (' . (int)max($rLot['ng_count'] ?? 0, $rLot['total_ng'] ?? 0) . ' pcs)</span>' 
                                                : '<span class="text-slate-400 font-medium">-</span>';
                                        }
                                    ?>
                                        <tr class="<?= getLotRowClass($rLot) ?>">
                                            <td style="padding: 9px 10px; text-align: center; font-weight: 700; color: #94a3b8;"><?= $rowNum ?></td>
                                            <td style="padding: 9px 10px; text-align: center; font-family: monospace; color: #334155; white-space: nowrap;">
                                                <?php
                                                $startTimeTs = !empty($rLot['scanned_at']) ? strtotime($rLot['scanned_at']) : (!empty($rLot['session_started_at']) ? strtotime($rLot['session_started_at']) : 0);
                                                
                                                $endTimeTs = 0;
                                                if (!empty($rLot['closed_at'])) {
                                                    $endTimeTs = strtotime($rLot['closed_at']);
                                                } elseif (!empty($rLot['action_noted_at'])) {
                                                    $endTimeTs = strtotime($rLot['action_noted_at']);
                                                } elseif (!empty($rLot['session_closed_at'])) {
                                                    $endTimeTs = strtotime($rLot['session_closed_at']);
                                                }
                                                
                                                $dateDisplay = $startTimeTs ? date('d/m/Y', $startTimeTs) : '-';
                                                $startTimeStr = $startTimeTs ? date('H:i', $startTimeTs) : '';
                                                $endTimeStr = $endTimeTs ? date('H:i', $endTimeTs) : '';
                                                
                                                $timeRangeStr = '-';
                                                if ($startTimeStr && $endTimeStr) {
                                                    $timeRangeStr = $startTimeStr . ' - ' . $endTimeStr . ' WIB';
                                                } elseif ($startTimeStr) {
                                                    $timeRangeStr = $startTimeStr . ' WIB';
                                                }

                                                // Calculate duration
                                                $durStr = '';
                                                if ($startTimeTs > 0 && $endTimeTs >= $startTimeTs) {
                                                    $diffSec = $endTimeTs - $startTimeTs;
                                                    $hrs = floor($diffSec / 3600);
                                                    $mins = floor(($diffSec % 3600) / 60);
                                                    $secs = $diffSec % 60;
                                                    if ($hrs > 0) {
                                                        $durStr = "{$hrs}j {$mins}m";
                                                    } elseif ($mins > 0) {
                                                        $durStr = "{$mins}m {$secs}s";
                                                    } else {
                                                        $durStr = "{$secs}s";
                                                    }
                                                }
                                                ?>
                                                <div style="font-weight: 800; color: #0f172a;"><?= $dateDisplay ?></div>
                                                <div style="font-size: 9.5px; color: #64748b; font-weight: 600; font-family: sans-serif; margin-top: 1px;"><?= $timeRangeStr ?></div>
                                                <?php if ($durStr !== ''): ?>
                                                    <div style="margin-top: 2px;">
                                                        <span class="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded text-[9.5px] font-extrabold bg-slate-100 text-slate-700 border border-slate-200" title="Durasi Inspeksi Lot">
                                                            ⏱️ <?= $durStr ?>
                                                        </span>
                                                    </div>
                                                <?php elseif (($rLot['status_code'] ?? '') === 'in_progress' || empty($endTimeTs)): ?>
                                                    <div style="margin-top: 2px;">
                                                        <span class="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded text-[9.5px] font-bold bg-amber-50 text-amber-700 border border-amber-200" title="Inspeksi Masih Berjalan">
                                                            ⏳ Aktif
                                                        </span>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td style="padding: 9px 10px; text-align: center; white-space: nowrap;">
                                                <?php if (($rLot['inspection_type'] ?? '') === 'safety_stock'): ?>
                                                    <span style="display: inline-flex; align-items: center; gap: 4px; padding: 2.5px 8.5px; border-radius: 9999px; font-size: 10px; font-weight: 800; background-color: #f3e8ff; color: #6b21a8; border: 1px solid #d8b4fe; box-shadow: 0 1px 2px rgba(107,33,168,0.06);" title="Inspeksi Safety Stock Gudang">
                                                        <svg width="11" height="11" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="color: #9333ea;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                                                        <span>Safety Stock</span>
                                                    </span>
                                                <?php else: ?>
                                                    <span style="display: inline-flex; align-items: center; gap: 4px; padding: 2.5px 8.5px; border-radius: 9999px; font-size: 10px; font-weight: 800; background-color: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; box-shadow: 0 1px 2px rgba(29,78,216,0.06);" title="Inspeksi Jadwal Kanban">
                                                        <svg width="11" height="11" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="color: #2563eb;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                                                        <span>Kanban</span>
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="padding: 9px 10px; font-family: monospace; font-weight: 800; color: #0284c7; white-space: nowrap;">
                                                <?= htmlspecialchars($rLot['part_code']) ?>
                                            </td>
                                            <td style="padding: 9px 10px; font-weight: 700; color: #1e293b;">
                                                <?= htmlspecialchars($rLot['part_name']) ?>
                                            </td>
                                            <td style="padding: 9px 10px; text-align: center; font-weight: 700; color: #64748b;">
                                                <?= htmlspecialchars($rLot['model_name']) ?>
                                            </td>
                                            <td style="padding: 9px 10px; font-family: monospace; font-weight: 700; color: #334155; white-space: nowrap;">
                                                <?= htmlspecialchars($rLot['lot_number'] ?: '-') ?>
                                            </td>
                                            <td style="padding: 9px 10px; font-family: monospace; font-weight: 800; color: #7c3aed; white-space: nowrap;">
                                                <div><?= htmlspecialchars($rLot['ref_number'] ?: '-') ?></div>
                                                
                                            </td>
                                            <td style="padding: 9px 10px; text-align: right; font-family: monospace; font-weight: 800; color: #0f172a; white-space: nowrap;">
                                                <?= number_format($rLot['qty_lot']) ?> pcs
                                            </td>
                                            <td style="padding: 9px 10px; text-align: center; font-family: monospace; font-weight: 700; color: #2563eb; white-space: nowrap;">
                                                <?= !empty($rLot['is_safety_stock']) ? '<span style="color:#7c3aed;font-weight:800;">0 (Lolos SS)</span>' : ((int)$rLot['sample_size'] . ' pcs') ?>
                                            </td>
                                            <td style="padding: 9px 10px; text-align: center; white-space: nowrap;">
                                                <span style="font-family: monospace; font-size: 11px; font-weight: 800; padding: 2px 7px; border-radius: 4px; <?= $hasNg ? 'background: #fef2f2; color: #dc2626; border: 1px solid #fecaca;' : 'background: #f0fdf4; color: #16a34a; border: 1px solid #dcfce7;' ?>">
                                                    <?= (int)$rLot['ng_count'] ?>
                                                </span>
                                            </td>
                                            <td style="padding: 9px 10px;">
                                                <?= $defectHtml ?>
                                            </td>
                                            <td style="padding: 9px 10px; text-align: center; white-space: nowrap;">
                                                <?= renderLotStatusBadge($rLot) ?>
                                            </td>
                                            <td style="padding: 9px 10px; text-align: center; font-family: monospace; font-weight: 800; white-space: nowrap;">
                                                <a href="<?= base_url('modules/inspection/session.php?id=' . $rLot['session_id']) ?>" target="_blank" class="px-2 py-0.5 rounded <?= $isReinspectLot ? 'bg-amber-100 text-amber-900 border-amber-300 hover:bg-amber-200' : 'bg-blue-50 text-blue-700 hover:bg-blue-100 border-blue-200' ?> border inline-flex items-center gap-1 font-mono font-bold text-[11px]" title="<?= $isReinspectLot ? 'Buka Detail Sesi Re-Inspeksi' : 'Buka Detail Sesi' ?>">
                                                    <span>Detail</span>
                                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                                                </a>
                                            </td>
                                            <td style="padding: 9px 10px; text-align: center; font-family: monospace; font-weight: 700; color: #475569; white-space: nowrap;">
                                                <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-slate-100 text-slate-700 border border-slate-200">
                                                    <?= htmlspecialchars($shiftName) ?>
                                                </span>
                                            </td>
                                            <td style="padding: 9px 10px; font-weight: 700; color: #334155; white-space: nowrap;">
                                                <div class="flex items-center gap-1.5" title="QC Inspector: <?= htmlspecialchars($rLot['inspector_name'] ?? 'QC Inspector') ?>">
                                                    <div class="w-5 h-5 rounded-full bg-purple-100 text-purple-700 font-extrabold flex items-center justify-center text-[10px] border border-purple-200 uppercase">
                                                        <?= mb_substr($rLot['inspector_name'] ?? 'Q', 0, 1) ?>
                                                    </div>
                                                    <span class="truncate max-w-[110px]"><?= htmlspecialchars($rLot['inspector_name'] ?? 'QC Inspector') ?></span>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
