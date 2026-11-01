<!-- Modal Riwayat Inspeksi Sesi Terdahulu untuk Part Ini -->
<div id="prev-history-modal-overlay" style="position: fixed; inset: 0; background-color: rgba(15, 23, 42, 0.75); z-index: 9999; display: flex; align-items: center; justify-content: center; padding: 16px;" class="hidden">
    <div style="background-color: #ffffff; border-radius: 20px; max-width: 620px; width: 100%; max-height: 88vh; display: flex; flex-direction: column; border: 1px solid #cbd5e1; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25); margin: auto; overflow: hidden;" class="animate-fadeIn">
        
        <!-- Modal Header -->
        <div class="p-4 border-b border-slate-200 flex items-center justify-between bg-slate-50 flex-shrink-0">
            <div>
                <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">
                    <?= ($session && !empty($session['kanban_no'])) ? ('RIWAYAT INSPEKSI KANBAN NO: KB-' . htmlspecialchars($session['kanban_no'])) : 'RIWAYAT INSPEKSI SESI TERDAHULU' ?>
                </span>
                <h3 class="text-sm font-extrabold text-slate-900 flex items-center">
                    <span class="font-mono text-blue-700 mr-1.5"><?= $session ? htmlspecialchars($session['part_code']) : '' ?></span>
                    <span class="text-slate-600 text-xs font-semibold">(<?= $session ? htmlspecialchars($session['display_part_name'] ?? $session['part_name']) : '' ?>)</span>
                </h3>
            </div>
            <button type="button" onclick="closePrevHistoryModal()" class="text-slate-400 hover:text-slate-600 font-bold text-xl leading-none px-2">&times;</button>
        </div>

        <!-- Modal Body: Cards List of Previous Inspection Sessions (Strictly Scrollable Flex-1 Container) -->
        <div style="min-height: 0; flex: 1; max-height: 65vh; overflow-y: auto; -webkit-overflow-scrolling: touch;" class="p-4 space-y-3">
            <?php if (empty($previousSessions)): ?>
                <div class="text-center py-8 space-y-2">
                    <svg class="w-10 h-10 text-slate-300 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    <p class="text-xs font-bold text-slate-500">
                        <?= ($session && !empty($session['kanban_no'])) ? ('Belum ada riwayat sesi inspeksi / re-inspeksi lain untuk Kanban No: KB-' . htmlspecialchars($session['kanban_no'])) : 'Belum ada riwayat sesi inspeksi lain.' ?>
                    </p>
                    <p class="text-[11px] text-slate-400">Sesi saat ini adalah sesi inspeksi pertama yang tercatat untuk item ini.</p>
                </div>
            <?php else: ?>
                <?php foreach ($previousSessions as $ps): 
                    $isReinspection = (!empty($ps['parent_session_id']) && $ps['parent_session_id'] > 0) || !empty($ps['reinspection_notes']);
                    $inspType = $ps['inspection_type'] ?? 'kanban';
                ?>
                    <div class="p-3 bg-slate-50 border border-slate-200 rounded-xl space-y-2 hover:border-slate-300 transition-colors">
                        <div class="flex items-center justify-between flex-wrap gap-1">
                            <div class="flex items-center space-x-1.5 flex-wrap gap-y-1">
                                <!-- Inspection Type Badge -->
                                <?php if ($isReinspection): ?>
                                    <span class="bg-amber-100 text-amber-900 border border-amber-300 text-[9.5px] font-black px-2 py-0.5 rounded-full inline-flex items-center">⚡ RE-INSPEKSI</span>
                                <?php elseif ($inspType === 'safety_stock'): ?>
                                    <span class="bg-purple-100 text-purple-900 border border-purple-300 text-[9.5px] font-black px-2 py-0.5 rounded-full inline-flex items-center">📦 SAFETY STOCK</span>
                                <?php else: ?>
                                    <span class="bg-blue-100 text-blue-900 border border-blue-300 text-[9.5px] font-black px-2 py-0.5 rounded-full inline-flex items-center">📋 KANBAN</span>
                                <?php endif; ?>

                                <span class="font-mono font-extrabold text-slate-900 text-xs">Lot: #<?= htmlspecialchars($ps['lot_number']) ?></span>
                                <?php if (!empty($ps['kanban_no'])): ?>
                                    <span class="text-[10px] text-slate-600 bg-slate-200/80 px-1.5 py-0.5 rounded font-mono font-bold">No. KB: <?= htmlspecialchars($ps['kanban_no']) ?></span>
                                <?php endif; ?>
                            </div>

                            <div>
                                <?php if ($ps['status'] === 'passed'): ?>
                                    <span class="badge badge-emerald text-[10px] font-extrabold">PASSED</span>
                                <?php elseif ($ps['status'] === 'rejected'): ?>
                                    <span class="badge badge-rose text-[10px] font-extrabold">REJECTED</span>
                                <?php else: ?>
                                    <span class="badge badge-blue text-[10px] font-extrabold">IN PROGRESS</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Defect Summary Pills -->
                        <div class="pt-1">
                            <?php if (!empty($ps['defects'])): ?>
                                <div class="flex items-center gap-1 flex-wrap">
                                    <span class="text-[10px] font-bold text-rose-800 mr-1">Temuan Defect:</span>
                                    <?php foreach ($ps['defects'] as $df): ?>
                                        <span class="bg-rose-100 text-rose-900 border border-rose-200 text-[9.5px] font-extrabold px-1.5 py-0.5 rounded">
                                            <?= htmlspecialchars($df['defect_name']) ?> ×<?= $df['total_qty_ng'] ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <span class="text-[10px] font-extrabold text-emerald-700 bg-emerald-50 border border-emerald-200 px-2 py-0.5 rounded inline-block">✅ Zero Defect (Lolos Full)</span>
                            <?php endif; ?>
                        </div>

                        <!-- Time, Inspector, & Sampling Details -->
                        <div class="grid grid-cols-2 gap-2 text-[11px] text-slate-600 border-t border-slate-200/60 pt-2">
                            <div>
                                <span class="text-[10px] text-slate-400 block font-bold uppercase">Waktu & Inspector</span>
                                <span class="font-semibold text-slate-800 block"><?= date('d M Y H:i', strtotime($ps['started_at'])) ?> WIB</span>
                                <span class="text-[10px] text-slate-500 block">by <?= htmlspecialchars($ps['inspector_name'] ?? $ps['did_pic'] ?? 'Inspector QC') ?></span>
                            </div>
                            <div class="text-right">
                                <span class="text-[10px] text-slate-400 block font-bold uppercase">Hasil Sampling & Total Qty</span>
                                <span class="font-mono font-extrabold text-slate-900 block"><?= number_format($ps['total_scanned_qty'] ?? 0) ?> pcs <span class="text-slate-400 font-normal">| Sample:</span> <?= $ps['samples_checked'] ?>/<?= $ps['sample_size'] ?></span>
                                <span class="text-[10px] font-mono block <?= ($ps['ng_count'] > 0) ? 'text-rose-600 font-bold' : 'text-slate-500' ?>">
                                    <?= $ps['ng_count'] ?> NG (Limit Reject: <?= $ps['reject_number'] ?>)
                                </span>
                            </div>
                        </div>

                        <div class="pt-1.5 border-t border-slate-200/40 flex items-center justify-between">
                            <span class="text-[10px] text-slate-400 font-mono">Sesi #<?= $ps['id'] ?></span>
                            <a href="<?= base_url('modules/inspection/session.php?id=' . $ps['id']) ?>" class="text-[11px] text-blue-600 font-bold hover:underline inline-flex items-center">
                                Lihat Sesi Inspeksi Ini &rarr;
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Modal Footer -->
        <div class="p-3 bg-slate-100 border-t border-slate-200 text-right flex-shrink-0">
            <button type="button" onclick="closePrevHistoryModal()" class="btn-secondary py-1 px-4 text-xs font-bold">
                Tutup
            </button>
        </div>

    </div>
</div>

