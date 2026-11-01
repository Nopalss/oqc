            <!-- Card 1: Top Part & Lot Metadata Summary Card (Full Color Edition) -->
            <?php
            $isSsSession = ($session && ($session['inspection_type'] ?? 'kanban') === 'safety_stock');
            ?>
            <div id="card-top-metadata" style="background: <?= $isSsSession ? 'linear-gradient(135deg, #ede9fe 0%, #f3e8ff 100%)' : 'linear-gradient(135deg, #dbeafe 0%, #eff6ff 100%)' ?>; border: 2px solid <?= $isSsSession ? '#c084fc' : '#93c5fd' ?>; border-left: 6px solid <?= $isSsSession ? '#7c3aed' : '#2563eb' ?>; border-radius: 16px; padding: 12px; margin-bottom: 10px; box-shadow: 0 2px 6px <?= $isSsSession ? 'rgba(124, 58, 237, 0.15)' : 'rgba(37, 99, 235, 0.15)' ?>;" class="space-y-2 flex-shrink-0 text-xs">
                <div class="flex items-center justify-between pb-1.5" style="border-bottom: 1px solid <?= $isSsSession ? '#ddd6fe' : '#bfdbfe' ?>;" id="card-meta-header-row">
                    <div class="flex items-center space-x-1.5 min-w-0">
                        <span class="text-[10px] font-extrabold uppercase tracking-wider flex-shrink-0" style="color: <?= $isSsSession ? '#6b21a8' : '#1e40af' ?>;" id="card-cust-label">CUST:</span>
                        <span class="font-extrabold text-[11px] flex items-center truncate max-w-[220px]" style="color: <?= $isSsSession ? '#4c1d95' : '#172554' ?>;" id="card-customer-name">
                            <?= $session ? htmlspecialchars($session['customer'] ?? 'PT. Indonesia Epson Industry') : '-' ?>
                        </span>
                    </div>
                    <div class="flex items-center space-x-1.5">
                        <button type="button" onclick="openKanbanDetailModal()" style="background-color: <?= $isSsSession ? '#7c3aed' : '#2563eb' ?>; color: #ffffff; border: none; padding: 3.5px 10px; border-radius: 7px; font-weight: 800; font-size: 10.5px; box-shadow: 0 1px 3px rgba(0,0,0,0.15); display: inline-flex; align-items: center; gap: 4px; cursor: pointer;" id="btn-card-detail" title="Lihat Rincian Detail Planning">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                            <span>Detail</span> 
                        </button>
                        <button type="button" id="btn-card-substitution-log" onclick="openSubstitutionLogModal()" class="hidden px-2 py-0.5 bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-[10px] rounded-md shadow-2xs inline-flex items-center gap-1 transition-all flex-shrink-0 cursor-pointer" title="Lihat Rincian Lineage Penggantian Lot & Ref No">
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                            <span>Substitusi (<span id="card-subst-log-count">0</span>)</span>
                        </button>
                    </div>
                </div>
                <div class="grid grid-cols-3 gap-1.5">
                    <div>
                        <span class="text-[10px] font-extrabold uppercase tracking-wider block mb-0.5" style="color: <?= $isSsSession ? '#6b21a8' : '#1e40af' ?>;" id="card-label-tipe">TIPE INSPEKSI</span>
                        <span id="card-type-badge">
                        <?php if ($isSsSession): ?>
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-extrabold bg-purple-700 text-white border border-purple-800 shadow-xs truncate">
                                <svg style="width: 11px; height: 11px; color: #ffffff; flex-shrink: 0;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                                <span>SAFETY STOCK</span>
                            </span>
                        <?php else: ?>
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-extrabold bg-blue-700 text-white border border-blue-800 shadow-xs truncate">
                                <svg style="width: 11px; height: 11px; color: #ffffff; flex-shrink: 0;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                <span>KANBAN</span>
                            </span>
                        <?php endif; ?>
                        </span>
                    </div>
                    <div class="min-w-0">
                        <span class="text-[10px] font-extrabold uppercase tracking-wider block mb-0.5" style="color: <?= $isSsSession ? '#6b21a8' : '#1e40af' ?>;" id="card-label-part">PART CODE</span>
                        <span class="font-mono font-black text-xs truncate block" style="color: <?= $isSsSession ? '#4c1d95' : '#172554' ?>;" id="card-part-code"><?= $session ? htmlspecialchars($session['part_code']) : '-' ?></span>
                        <span class="block text-[10px] font-semibold leading-tight line-clamp-2 mt-0.5 break-words" style="color: <?= $isSsSession ? '#7c3aed' : '#2563eb' ?>;" id="card-part-name" title="<?= $session ? htmlspecialchars($session['part_name'] ?? $session['display_part_name'] ?? $session['kanban_item_desc'] ?? '') : '' ?>"><?= $session ? htmlspecialchars($session['part_name'] ?? $session['display_part_name'] ?? $session['kanban_item_desc'] ?? '-') : '-' ?></span>
                    </div>
                    <div>
                        <span class="text-[10px] font-extrabold uppercase tracking-wider block mb-0.5" style="color: <?= $isSsSession ? '#6b21a8' : '#1e40af' ?>;" id="card-label-qty">TOTAL QTY</span>
                        <?php 
                        $totalScanned = $session ? (int)$session['total_scanned_qty'] : 0;
                        $prevKbQty = $session ? (int)($session['prev_kanban_qty'] ?? 0) : 0;
                        $accumulatedQty = $totalScanned + $prevKbQty;
                        $kanbanTarget = $session ? (int)($session['kanban_qty'] ?? 0) : 0;
                        $usedSs = $session ? (int)($session['use_safety_stock_qty'] ?? 0) : 0;
                        $excessQty = $session ? (int)($session['excess_qty'] ?? 0) : 0;
                        $isKanbanMode = !$session || ($session['inspection_type'] ?? 'kanban') === 'kanban';

                        if ($isKanbanMode) {
                            $effectiveQty = ($prevKbQty > 0) ? $accumulatedQty : $totalScanned;
                            $targetDisplay = ($kanbanTarget > 0) ? $kanbanTarget : max(500, $effectiveQty);
                            $mainQtyText = number_format($effectiveQty) . ' / ' . number_format($targetDisplay) . ' pcs';
                        } else {
                            $mainQtyText = number_format($totalScanned) . ' pcs';
                        }
                        ?>
                        <span class="font-black text-xs block" style="color: <?= $isSsSession ? '#581c87' : '#1d4ed8' ?>;" id="card-total-qty"><?= $mainQtyText ?></span>

                        <div id="card-qty-subbadge">
                            <?php if ($isKanbanMode && $prevKbQty > 0): ?>
                                <div class="mt-0.5" style="line-height: 1.2;">
                                    <div style="font-size: 8px; color: #64748b; font-weight: 600; white-space: nowrap;">
                                        Sesi ini: <b><?= number_format($totalScanned) ?></b> (+<?= number_format($prevKbQty) ?>)
                                    </div>
                                    <div style="margin-top: 1.5px;">
                                        <?php if ($kanbanTarget > 0): ?>
                                            <?php if ($accumulatedQty === $kanbanTarget): ?>
                                                <span style="font-size: 7.5px; font-weight: 800; background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; padding: 0.5px 3.5px; border-radius: 3px; display: inline-block;">PAS KANBAN</span>
                                            <?php elseif ($accumulatedQty > $kanbanTarget): ?>
                                                <span style="font-size: 7.5px; font-weight: 800; background: #fef3c7; color: #92400e; border: 1px solid #fde68a; padding: 0.5px 3.5px; border-radius: 3px; display: inline-block;">LEBIH +<?= number_format($accumulatedQty - $kanbanTarget) ?> PCS</span>
                                            <?php else: ?>
                                                <span style="font-size: 7.5px; font-weight: 800; background: #dbeafe; color: #1e40af; border: 1px solid #bfdbfe; padding: 0.5px 3.5px; border-radius: 3px; display: inline-block;">KURANG <?= number_format($kanbanTarget - $accumulatedQty) ?> PCS</span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Card 2: Ringkasan Sesi & Statistik Lot (Per-Lot AQL Flow) -->
            <div id="card-session-summary" style="background-color: #ffffff; border: 1px solid #cbd5e1; border-radius: 16px; padding: 12px; margin-bottom: 10px; box-shadow: 0 1px 2px 0 rgba(0,0,0,0.05);" class="flex-shrink-0">
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px;">
                    <span style="font-size: 10px; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em;">RINGKASAN INSPEKSI SESI</span>
                    <span id="badge-session-status" class="px-2.5 py-0.5 rounded-full text-[10px] font-extrabold shadow-2xs">
                        <!-- Populated by JS -->
                    </span>
                </div>
                <div class="grid grid-cols-2 gap-2 text-xs">
                    <div style="background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 8px 10px;">
                        <span style="color: #64748b; font-size: 10px; font-weight: 700; display: block; text-transform: uppercase; letter-spacing: 0.03em;">PROGRES INSPEKSI LOT</span>
                        <span id="summary-lot-progress" style="color: #1e293b; font-size: 15px; font-weight: 900; font-family: monospace;">0 / 0 Lot</span>
                        <span id="summary-lot-subtext" style="color: #94a3b8; font-size: 9.5px; display: block; margin-top: 1px;">Menunggu pemeriksaan</span>
                    </div>
                    <div style="background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 8px 10px;">
                        <span style="color: #64748b; font-size: 10px; font-weight: 700; display: block; text-transform: uppercase; letter-spacing: 0.03em;">TOTAL SAMPEL DIINSPEKSI</span>
                        <span id="summary-sample-total" style="color: #2563eb; font-size: 15px; font-weight: 900; font-family: monospace;">0 pcs</span>
                        <span id="summary-sample-subtext" style="color: #94a3b8; font-size: 9.5px; display: block; margin-top: 1px;">Akumulasi sampel fisik AQL</span>
                    </div>
                </div>
            </div>

            <!-- Card 3: Inspeksi Per-Box (Focus Box & Ringkasan Seluruh Box) -->
            <div id="lot-cards-wrapper" style="flex-shrink: 0; display: flex; flex-direction: column; margin-bottom: 12px;">
                <!-- Header Box & Tombol Tambah Box & Tandai Semua Passed -->
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; padding: 0 2px; flex-wrap: wrap; gap: 6px;">
                    <div style="display: flex; align-items: center; gap: 6px;">
                        <span style="font-size: 11px; font-weight: 800; color: #334155; text-transform: uppercase; letter-spacing: 0.05em;">INSPEKSI PER-BOX</span>
                        <span id="lot-count-badge" style="font-size: 10px; font-weight: 800; color: #2563eb; background-color: #eff6ff; border: 1px solid #bfdbfe; padding: 1px 7px; border-radius: 9999px;">0 Lot</span>
                    </div>
                    <div style="display: flex; align-items: center; gap: 6px;">
                        <button type="button" id="btn-add-lot" onclick="openAddLotModal()" style="display: none; padding: 5px 12px; background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); color: #ffffff; border: 1.5px solid #1e40af; border-radius: 8px; font-size: 10.5px; font-weight: 800; cursor: pointer; align-items: center; gap: 5px; transition: all 0.15s; box-shadow: 0 2px 5px rgba(37,99,235,0.28);" onmouseover="this.style.filter='brightness(1.08)'" onmouseout="this.style.filter='none'" title="Scan barcode QR atau tambah box baru ke sesi ini">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                            <span>Tambah Box</span>
                        </button>
                        <button type="button" id="btn-pass-all-lots" onclick="confirmPassAllLots()" style="display: none; padding: 5px 12px; background: linear-gradient(135deg, #059669 0%, #047857 100%); color: #ffffff; border: 1.5px solid #065f46; border-radius: 8px; font-size: 10.5px; font-weight: 800; cursor: pointer; align-items: center; gap: 5px; transition: all 0.15s; box-shadow: 0 2px 5px rgba(5,150,105,0.28);" onmouseover="this.style.filter='brightness(1.08)'" onmouseout="this.style.filter='none'" title="Loloskan seluruh sisa box yang belum dicek sekaligus">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                            <span>Tandai Semua Box PASSED</span>
                        </button>
                    </div>
                </div>

                <!-- SECTION 1: Active Focus Box (Kotak Box yang Sedang Diperiksa) -->
                <div id="active-focus-box-section" style="background-color: #ffffff; border: 1.5px solid #cbd5e1; border-radius: 14px; padding: 9px 11px; margin-bottom: 8px; box-shadow: 0 2px 6px rgba(0,0,0,0.03);">
                    <!-- Selector Dropdown & Navigasi Prev/Next -->
                    <div style="display: flex; align-items: center; gap: 5px; margin-bottom: 7px;">
                        <button type="button" id="btn-focus-prev" onclick="navigateFocusBox(-1)" style="width: 30px; height: 30px; padding: 0; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 11px; font-weight: 700; color: #475569; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; transition: all 0.15s;" title="Box Sebelumnya">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
                        </button>
                        
                        <div style="flex: 1; min-width: 0;">
                            <select id="focus-lot-select" onchange="changeFocusBox(this.value)" style="width: 100%; height: 30px; padding: 3px 8px; font-size: 10.5px; font-weight: 700; color: #0f172a; background-color: #f8fafc; border: 1.5px solid #cbd5e1; border-radius: 8px; outline: none; cursor: pointer;">
                                <option value="">Memuat daftar box...</option>
                            </select>
                        </div>
                        
                        <button type="button" id="btn-focus-next" onclick="navigateFocusBox(1)" style="width: 30px; height: 30px; padding: 0; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 11px; font-weight: 700; color: #475569; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; transition: all 0.15s;" title="Box Berikutnya">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                        </button>
                    </div>

                    <!-- Mini Numbered Box Chips ([1] [2] ... [21]) -->
                    <div id="focus-box-chips-container" style="display: flex; gap: 5px; overflow-x: auto; padding: 4px 4px 6px 4px; margin-bottom: 7px;" class="custom-scrollbar">
                        <!-- Populated by JS -->
                    </div>

                    <!-- Card Body Box Aktif -->
                    <div id="active-focus-card-body">
                        <div class="text-center py-6 text-slate-400 text-xs">Memuat data pemeriksaan box...</div>
                    </div>
                </div>

                <!-- SECTION 2: Toggle Lihat Ringkasan Seluruh Box -->
                <div style="margin-bottom: 6px;">
                    <button type="button" id="btn-toggle-all-boxes" onclick="toggleAllBoxesView()" style="width: 100%; padding: 7px 12px; background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; font-size: 11px; font-weight: 800; color: #475569; cursor: pointer; display: flex; align-items: center; justify-content: space-between; transition: all 0.15s;" onmouseover="this.style.backgroundColor='#f1f5f9'" onmouseout="this.style.backgroundColor='#f8fafc'">
                        <div style="display: flex; align-items: center; gap: 6px;">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
                            <span id="all-boxes-toggle-title">Lihat Ringkasan Seluruh Box</span>
                        </div>
                        <svg id="all-boxes-chevron" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="transform: rotate(0deg); transition: transform 0.2s;"><polyline points="6 9 12 15 18 9"/></svg>
                    </button>
                </div>

                <!-- Konten Seluruh Box (Collapsible) -->
                <div id="all-boxes-content" style="display: none; flex-direction: column; flex: 1; min-height: 200px; overflow: hidden;">
                    <!-- Tab Filter Status Lot -->
                    <div style="display: flex; gap: 4px; margin-bottom: 8px; overflow-x: auto; padding-bottom: 2px;" class="custom-scrollbar">
                        <button type="button" id="tab-lot-filter-all" onclick="switchLotFilterTab('all')" style="padding: 4px 8px; font-size: 10px; font-weight: 800; border-radius: 8px; border: 1px solid #cbd5e1; background-color: #0f172a; color: #ffffff; cursor: pointer; display: inline-flex; align-items: center; gap: 4px; white-space: nowrap; transition: all 0.15s;">
                            <span>Semua</span>
                            <span id="tab-count-lot-all" style="background-color: rgba(255,255,255,0.2); padding: 0 5px; border-radius: 9999px; font-size: 9px;">0</span>
                        </button>
                        <button type="button" id="tab-lot-filter-pending" onclick="switchLotFilterTab('pending')" style="padding: 4px 8px; font-size: 10px; font-weight: 700; border-radius: 8px; border: 1px solid #e2e8f0; background-color: #ffffff; color: #64748b; cursor: pointer; display: inline-flex; align-items: center; gap: 4px; white-space: nowrap; transition: all 0.15s;">
                            <span>Belum Dicek</span>
                            <span id="tab-count-lot-pending" style="background-color: #fef3c7; color: #b45309; padding: 0 5px; border-radius: 9999px; font-size: 9px; font-weight: 800;">0</span>
                        </button>
                        <button type="button" id="tab-lot-filter-passed" onclick="switchLotFilterTab('passed')" style="padding: 4px 8px; font-size: 10px; font-weight: 700; border-radius: 8px; border: 1px solid #e2e8f0; background-color: #ffffff; color: #64748b; cursor: pointer; display: inline-flex; align-items: center; gap: 4px; white-space: nowrap; transition: all 0.15s;">
                            <span>Passed</span>
                            <span id="tab-count-lot-passed" style="background-color: #d1fae5; color: #065f46; padding: 0 5px; border-radius: 9999px; font-size: 9px; font-weight: 800;">0</span>
                        </button>
                        <button type="button" id="tab-lot-filter-rejected" onclick="switchLotFilterTab('rejected')" style="padding: 4px 8px; font-size: 10px; font-weight: 700; border-radius: 8px; border: 1px solid #e2e8f0; background-color: #ffffff; color: #64748b; cursor: pointer; display: inline-flex; align-items: center; gap: 4px; white-space: nowrap; transition: all 0.15s;">
                            <span>Rejected</span>
                            <span id="tab-count-lot-rejected" style="background-color: #ffe4e6; color: #be123c; padding: 0 5px; border-radius: 9999px; font-size: 9px; font-weight: 800;">0</span>
                        </button>
                    </div>

                    <div id="lot-cards-container" style="flex: 1; overflow-y: auto; padding-right: 2px;" class="space-y-2.5 custom-scrollbar">
                        <div class="text-center py-8 text-slate-400 text-xs">Memuat data lot...</div>
                    </div>
                </div>
            </div>

            <!-- Card 4: Banner Sesi Selesai (Muncul Jika Semua Lot Sudah Selesai) -->
            <div id="session-finished-banner-v2" class="hidden flex-shrink-0 p-3 bg-slate-100 border border-slate-200 rounded-2xl text-center space-y-2 mb-2">
                <span class="text-[11px] font-bold text-slate-700 uppercase block tracking-wider">HASIL AKHIR SESI INSPEKSI</span>
                <span id="finished-v2-status-badge" class="text-[11px] font-black uppercase text-white px-3 py-1 rounded-full inline-block shadow-2xs">
                    STATUS
                </span>

                <!-- Quick Next Actions for Passed Session (Lanjut Kanban / Ganti Kanban) -->
                <div id="v2-finished-next-actions" class="pt-1 hidden space-y-1.5">
                    <!-- Populated dynamically by JS -->
                </div>
                
                <!-- Chief QC Approval & Print Section -->
                <div id="finished-v2-actions-wrap" class="pt-1 space-y-2">
                    <div id="v2-chief-acc-wrap" class="p-2 bg-white border border-slate-200 rounded-xl flex items-center justify-between gap-2 shadow-2xs text-left">
                        <div class="min-w-0 flex-1">
                            <span class="text-[9.5px] font-bold text-slate-400 uppercase tracking-wider block">PERSETUJUAN (ACC) CHIEF QC</span>
                            <div id="v2-chief-status-text" class="text-[11px] font-extrabold truncate">
                                <!-- Populated by JS -->
                            </div>
                        </div>
                        <div class="flex-shrink-0" id="v2-chief-btn-container">
                            <!-- Populated by JS -->
                        </div>
                    </div>
                </div>
            </div>

