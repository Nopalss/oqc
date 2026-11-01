<!-- Modal Pop-up Rincian Detail Kanban / Planning -->
<div id="kanban-detail-modal-overlay" style="position: fixed; inset: 0; background-color: rgba(15, 23, 42, 0.75); z-index: 9999; display: flex; align-items: center; justify-content: center; padding: 16px;" class="hidden">
    
    <div style="background-color: #ffffff; border-radius: 24px; max-width: 560px; width: 100%; max-height: 85vh; padding: 20px; border: 1px solid #cbd5e1; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25); position: relative; margin: auto; display: flex; flex-direction: column; overflow: hidden;" class="animate-fadeIn space-y-2.5">
        
        <!-- Header Modal dengan tombol Close Rapi -->
        <div class="flex items-center justify-between border-b border-slate-100 pb-2.5 flex-shrink-0">
            <div class="flex items-center space-x-2.5">
                <div class="w-8 h-8 rounded-xl bg-blue-100 text-blue-700 flex items-center justify-center font-bold text-base shadow-2xs">
                    📋
                </div>
                <div>
                    <h3 class="text-sm font-extrabold text-slate-900 leading-tight">Detail Planning Kanban & Lot Produksi</h3>
                    <p class="text-[11px] text-slate-500 font-medium">Rincian metadata dokumen acuan, part model, & lot produksi</p>
                </div>
            </div>
            <button type="button" onclick="closeKanbanDetailModal()" class="w-8 h-8 rounded-lg hover:bg-slate-100 text-slate-400 hover:text-slate-600 font-bold text-xl flex items-center justify-center transition-all cursor-pointer">&times;</button>
        </div>

        <!-- Grid Detail Content Container (Explicitly Max-Height 50vh Scrollable) -->
        <div class="space-y-2.5 pr-1.5 flex-1 min-h-0" style="max-height: 48vh; overflow-y: auto !important; display: block;">
            
            <!-- Group 1: Informasi Dokumen Batch & Customer -->
            <div class="p-3 bg-slate-50 border border-slate-200/80 rounded-xl space-y-2 text-xs">
                <div class="flex items-center justify-between text-[11px] font-bold text-blue-900 border-b border-slate-200 pb-1">
                    <span>📌 METADATA BATCH & CUSTOMER</span>
                    <span id="modal-kb-plan-type" class="px-2 py-0.5 rounded text-[9px] font-black bg-blue-100 text-blue-800 uppercase">
                        <?= ($session && ($session['inspection_type'] ?? 'kanban') === 'safety_stock') ? 'SAFETY STOCK' : 'KANBAN' ?>
                    </span>
                </div>
                <div class="grid grid-cols-2 gap-2 text-slate-700">
                    <div>
                        <span class="text-[10px] text-slate-400 font-bold block uppercase">No. Dokumen Batch</span>
                        <span id="modal-kb-doc-no" class="font-mono font-extrabold text-slate-900 text-xs block truncate"><?= $session ? htmlspecialchars($session['doc_no'] ?? '-') : '-' ?></span>
                    </div>
                    <div>
                        <span class="text-[10px] text-slate-400 font-bold block uppercase">Customer Tujuan</span>
                        <span id="modal-kb-customer" class="font-extrabold text-blue-900 text-xs block truncate">🏢 <?= $session ? htmlspecialchars($session['customer'] ?? 'PT. Indonesia Epson Industry') : '-' ?></span>
                    </div>
                </div>
            </div>

            <!-- Group 2: Detail Part, Nama Model & Lot Produksi -->
            <div class="p-3 bg-blue-50/60 border border-blue-200 rounded-xl space-y-2 text-xs">
                <div class="text-[11px] font-bold text-blue-900 border-b border-blue-200/80 pb-1 flex items-center justify-between">
                    <span>🏷️ RINCIAN PART, MODEL & LOT PRODUKSI</span>
                    <span class="text-[10px] font-mono text-blue-700 font-extrabold" id="modal-kb-kanban-no-badge">#<?= $session ? htmlspecialchars($session['kanban_no'] ?? '-') : '-' ?></span>
                </div>
                <div class="grid grid-cols-2 gap-2 text-slate-700">
                    <div>
                        <span class="text-[10px] text-slate-400 font-bold block uppercase">Part Code</span>
                        <span id="modal-kb-item-code" class="font-mono font-black text-blue-700 text-xs block truncate"><?= $session ? htmlspecialchars($session['kanban_item_code'] ?? $session['part_code'] ?? '-') : '-' ?></span>
                    </div>
                    <div>
                        <span class="text-[10px] text-slate-400 font-bold block uppercase">Nama Model</span>
                        <span id="modal-kb-part-model" class="font-bold text-purple-800 text-xs block truncate"><?= ($session && !empty($session['part_model'])) ? htmlspecialchars($session['part_model']) : '-' ?></span>
                    </div>
                    <div class="col-span-2">
                        <span class="text-[10px] text-slate-400 font-bold block uppercase">Item Description / Nama Part</span>
                        <span id="modal-kb-item-desc" class="font-bold text-slate-900 text-xs block truncate"><?= $session ? htmlspecialchars($session['kanban_item_desc'] ?? $session['part_name'] ?? '-') : '-' ?></span>
                    </div>
                    <div class="col-span-2 pt-1 border-t border-blue-100">
                        <span class="text-[10px] text-slate-400 font-bold block uppercase">Cavity Moulding</span>
                        <span id="modal-kb-cavity" class="font-bold text-slate-800 text-xs block">Cavity <?= $session ? htmlspecialchars($session['cavity'] ?? '1') : '1' ?></span>
                    </div>
                </div>
            </div>

            <!-- Group 2.3: Akumulasi Sesi Kanban (Visible when previous session exists) -->
            <div id="modal-kb-accumulated-box" class="p-3 bg-emerald-50/70 border border-emerald-200 rounded-xl space-y-1.5 text-xs <?= (!empty($session['prev_kanban_qty'])) ? '' : 'hidden' ?>" style="<?= (!empty($session['prev_kanban_qty'])) ? '' : 'display: none;' ?>">
                <div class="text-[11px] font-bold text-emerald-900 border-b border-emerald-200/80 pb-1 flex items-center justify-between">
                    <span>AKUMULASI INSPEKSI KANBAN</span>
                    <span class="text-[10px] font-mono font-extrabold text-emerald-800" id="modal-kb-accumulated-status">
                        <?= (!empty($session['accumulated_kanban_qty']) && !empty($session['kanban_qty'])) ? number_format($session['accumulated_kanban_qty']) . ' / ' . number_format($session['kanban_qty']) . ' pcs' : '-' ?>
                    </span>
                </div>
                <div class="text-[10.5px] text-slate-700 space-y-1" id="modal-kb-sessions-list">
                    <?php if (!empty($session['prev_kanban_sessions'])): ?>
                        <?php foreach ($session['prev_kanban_sessions'] as $pks): ?>
                            <div class="flex items-center justify-between py-0.5 border-b border-emerald-100">
                                <span>Sesi #<?= $pks['id'] ?> (<?= htmlspecialchars($pks['status_text'] ?? 'Lulus') ?> - <?= htmlspecialchars($pks['inspector_name'] ?? 'QC Inspector') ?>):</span>
                                <span class="font-mono font-bold text-emerald-800"><?= number_format($pks['passed_qty'] ?? $pks['total_scanned_qty']) ?> pcs</span>
                            </div>
                        <?php endforeach; ?>
                        <div class="flex items-center justify-between py-0.5 font-bold text-emerald-950">
                            <span>Sesi #<?= $sessionId ?> (Sesi Berjalan):</span>
                            <span class="font-mono" id="modal-kb-current-sess-qty"><?= number_format($session['total_scanned_qty'] ?? 0) ?> pcs</span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Group 2.5: Rincian Box Label Barcode & Multi-Lot Breakdown -->
            <div class="p-3 bg-indigo-50/70 border border-indigo-200 rounded-xl space-y-2 text-xs">
                <div class="text-[11px] font-bold text-indigo-900 border-b border-indigo-200/80 pb-1 flex items-center justify-between">
                    <span>📦 RINCIAN LABEL BOX & MULTI-LOT PRODUKSI (<span id="modal-kb-lots-count"><?= isset($sessionLots) ? count($sessionLots) : 0 ?></span> Box)</span>
                    <span class="text-[10px] font-mono text-indigo-800 font-extrabold" id="modal-kb-lots-total-qty"><?= $session ? number_format(($session['total_scanned_qty'] && (int)$session['total_scanned_qty'] > 0) ? $session['total_scanned_qty'] : ($session['kanban_qty'] ?? 0)) : '0' ?> pcs</span>
                </div>

                <!-- Aggregated Lot Summary Cards (Visible when multiple Lot Numbers exist) -->
                <div id="modal-kb-lot-summary-container" class="grid grid-cols-2 gap-1.5 <?= (isset($lotSummaryList) && count($lotSummaryList) > 1) ? '' : 'hidden' ?>">
                    <?php if (isset($lotSummaryList) && count($lotSummaryList) > 1): ?>
                        <?php foreach ($lotSummaryList as $ls): ?>
                            <div class="p-1.5 bg-white border border-indigo-200 rounded-lg flex items-center justify-between shadow-2xs">
                                <span class="font-mono font-bold text-slate-800 text-[10px]">#<?= htmlspecialchars($ls['lot_number']) ?></span>
                                <span class="font-mono font-extrabold text-blue-800 text-[10px]"><?= number_format($ls['total_qty']) ?> pcs (<?= $ls['label_count'] ?> Box)</span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Detailed Scanned Session Lots Table -->
                <div class="border border-indigo-200 rounded-lg overflow-hidden max-h-40 overflow-y-auto bg-white shadow-2xs">
                    <table class="w-full text-left text-[11px]">
                        <thead class="bg-indigo-100/70 text-indigo-900 font-bold border-b border-indigo-200 uppercase text-[9px]">
                            <tr>
                                <th class="px-2.5 py-1.5">No</th>
                                <th class="px-2.5 py-1.5">Ref No. Box</th>
                                <th class="px-2.5 py-1.5">Lot No.</th>
                                <th class="px-2.5 py-1.5 text-right">Qty Box</th>
                            </tr>
                        </thead>
                        <tbody id="modal-kb-session-lots-tbody" class="divide-y divide-indigo-50 text-slate-700">
                            <?php if (empty($sessionLots)): ?>
                                <tr>
                                    <td colspan="4" class="px-2.5 py-3 text-center text-slate-400 italic">Belum ada rincian label box yang tercatat.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($sessionLots as $idx => $lbl): ?>
                                    <?php 
                                    $isSs = (!empty($lbl['remarks']) && strpos($lbl['remarks'], 'Safety Stock') !== false) || (!empty($lbl['is_safety_stock']));
                                    ?>
                                    <tr class="hover:bg-indigo-50/50 transition-colors">
                                        <td class="px-2.5 py-1.5 font-bold text-slate-400"><?= $idx + 1 ?></td>
                                        <td class="px-2.5 py-1.5 font-mono font-bold text-blue-800 text-[10px]">
                                            <span class="bg-blue-50 px-1.5 py-0.5 rounded border border-blue-200"><?= htmlspecialchars($lbl['ref_number'] ?? '-') ?></span>
                                            <?php if ($isSs): ?>
                                                <span class="bg-purple-100 text-purple-900 border border-purple-300 text-[9px] font-black px-2 py-0.5 rounded-full inline-flex items-center ml-1" title="Lot dipenuhi dari Alokasi Safety Stock">
                                                    Safety Stock
                                                </span>
                                            <?php else: ?>
                                                <span class="bg-blue-100 text-blue-900 border border-blue-300 text-[9px] font-black px-2 py-0.5 rounded-full inline-flex items-center ml-1" title="Lot berasal dari scan label box">
                                                    Scan Label
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-2.5 py-1.5 font-mono font-bold text-slate-800"><?= htmlspecialchars($lbl['lot_number'] ?? $session['lot_number'] ?? '-') ?></td>
                                        <td class="px-2.5 py-1.5 font-mono font-extrabold text-slate-900 text-right"><?= number_format($lbl['qty'] ?? 0) ?> pcs</td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Group 3: Jadwal & Lokasi -->
            <div class="p-3 bg-slate-50 border border-slate-200/80 rounded-xl space-y-2 text-xs">
                <div class="text-[11px] font-bold text-slate-800 border-b border-slate-200 pb-1">
                    🚚 JADWAL PENGIRIMAN & SPESIFIKASI LOKASI
                </div>
                <div class="grid grid-cols-2 gap-2 text-slate-700">
                    <div>
                        <span class="text-[10px] text-slate-400 font-bold block uppercase">Jadwal Tiba (ETA)</span>
                        <span id="modal-kb-eta" class="font-extrabold text-slate-900 text-xs block truncate">
                            <?= ($session && !empty($session['kanban_eta'])) ? date('d M Y, H:i', strtotime($session['kanban_eta'])) . ' WIB' : '-' ?>
                        </span>
                    </div>
                    <div>
                        <span class="text-[10px] text-slate-400 font-bold block uppercase">Req. Date</span>
                        <span id="modal-kb-req-date" class="font-extrabold text-slate-900 text-xs block truncate">
                            <?= ($session && !empty($session['kanban_req_date'])) ? date('d M Y, H:i', strtotime($session['kanban_req_date'])) . ' WIB' : '-' ?>
                        </span>
                    </div>
                    <div>
                        <span class="text-[10px] text-slate-400 font-bold block uppercase">Target Qty</span>
                        <span id="modal-kb-qty" class="font-black text-blue-700 text-xs block"><?= $session ? number_format($session['kanban_qty'] ?? 0) : '0' ?> pcs</span>
                    </div>
                    <div>
                        <span class="text-[10px] text-slate-400 font-bold block uppercase">Storage Location</span>
                        <span id="modal-kb-str-loc" class="font-mono font-bold text-slate-800 text-xs block"><?= ($session && !empty($session['kanban_str_loc'])) ? htmlspecialchars($session['kanban_str_loc']) : '-' ?></span>
                    </div>
                    <div>
                        <span class="text-[10px] text-slate-400 font-bold block uppercase">Status Cek</span>
                        <span id="modal-kb-check-type" class="font-extrabold text-amber-800 text-xs block"><?= ($session && !empty($session['kanban_check_type'])) ? htmlspecialchars($session['kanban_check_type']) . ' Cek' : 'Normal Cek' ?></span>
                    </div>
                    <div>
                        <span class="text-[10px] text-slate-400 font-bold block uppercase">Catatan (Remark)</span>
                        <span id="modal-kb-remark" class="font-semibold text-slate-600 text-xs block truncate"><?= ($session && !empty($session['kanban_remark'])) ? htmlspecialchars($session['kanban_remark']) : '-' ?></span>
                    </div>
                </div>

                <!-- Group 4: Rincian Penggantian Lot & Ref No (Jika Ada) -->
                <div id="modal-kb-substitution-container" class="hidden pt-3 border-t border-slate-200">
                    <span class="text-[11px] font-bold text-slate-800 uppercase block mb-1.5 flex items-center gap-1">
                        <span>🔄</span> LINIMASA PENGGANTIAN LOT &amp; REF NO
                    </span>
                    <div id="modal-kb-substitution-list" class="space-y-1.5"></div>
                </div>
            </div>

        </div>

        <div class="pt-2.5 border-t border-slate-100 text-right flex-shrink-0">
            <button type="button" onclick="closeKanbanDetailModal()" class="btn-secondary py-1.5 px-4 text-xs font-bold shadow-2xs hover:bg-slate-100 cursor-pointer">
                Tutup
            </button>
        </div>

    </div>
</div>

