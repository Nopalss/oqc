<!-- MODAL DIRECT CLOSE KANBAN (OPTION A: DIRECT PASS) -->
    <div id="modalDirectCloseKanban" 
         style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; width: 100vw; height: 100vh; background-color: rgba(15, 23, 42, 0.65); z-index: 99999; display: none; align-items: center; justify-content: center; padding: 16px; box-sizing: border-box;"
         onclick="if(event.target === this) closeDirectCloseModal();">
        <div style="max-width: 520px; width: 100%; margin: auto; border: 1px solid #e2e8f0; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); border-radius: 16px; overflow: hidden; background-color: #ffffff;">
            
            <!-- Header Modal Solid Emerald -->
            <div style="background-color: #065f46; color: #ffffff; padding: 16px 22px;" class="flex items-center justify-between">
                <div class="flex items-center space-x-3">
                    <div style="width: 38px; height: 38px; background-color: rgba(255, 255, 255, 0.18); border: 1px solid rgba(255, 255, 255, 0.25);" class="rounded-xl flex items-center justify-center flex-shrink-0">
                        <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
                        </svg>
                    </div>
                    <div>
                        <h3 style="font-size: 15px; font-weight: 800; color: #ffffff; line-height: 1.2;">Konfirmasi Direct Pass (Tutup Kanban)</h3>
                        <p style="font-size: 11px; color: #a7f3d0; margin-top: 2px;">Tutup Kanban tanpa scan fisik · Data otomatis masuk KPI PPM</p>
                    </div>
                </div>
                <button type="button" onclick="closeDirectCloseModal()" style="color: #ffffff; background-color: rgba(255,255,255,0.15);" class="w-8 h-8 rounded-lg hover:opacity-80 flex items-center justify-center font-bold text-sm cursor-pointer transition-opacity">
                    ✕
                </button>
            </div>

            <!-- Body Form -->
            <form id="formDirectCloseKanban" onsubmit="submitDirectClose(event)" class="p-6 space-y-4">
                <input type="hidden" id="dc_kanban_id" name="kanban_id" value="">

                <!-- Kartu Ringkasan Kanban & Part -->
                <div style="background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 14px;" class="space-y-2">
                    <div class="flex items-center justify-between">
                        <span style="font-size: 10px; font-weight: 800; color: #64748b; letter-spacing: 0.5px; text-transform: uppercase;">TARGET KANBAN</span>
                        <span id="dc_customer" style="font-size: 11px; font-weight: 700; color: #334155;" class="truncate max-w-[240px]">-</span>
                    </div>
                    <div style="border-bottom: 1px solid #e2e8f0; padding-bottom: 8px;" class="flex items-center justify-between">
                        <span class="text-sm font-black text-slate-900 font-mono" id="dc_kanban_no">#---</span>
                        <span id="dc_part_code" style="background-color: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; font-family: monospace; font-weight: 800; font-size: 12px; padding: 2px 8px; border-radius: 6px;">---</span>
                    </div>
                    <div>
                        <span style="font-size: 10px; font-weight: 800; color: #64748b; letter-spacing: 0.5px; text-transform: uppercase; display: block;">NAMA PART</span>
                        <span id="dc_part_name" style="font-size: 12px; font-weight: 800; color: #0f172a; margin-top: 2px; display: block;" class="line-clamp-1">---</span>
                    </div>
                </div>

                <!-- Kalkulasi Sisa Qty & AQL Sample -->
                <div class="grid grid-cols-2 gap-3">
                    <div style="background-color: #fffbeb; border: 1px solid #fde68a; border-radius: 12px; padding: 12px;">
                        <span style="font-size: 10px; font-weight: 800; color: #92400e; letter-spacing: 0.5px; text-transform: uppercase; display: block;">QTY DITUTUP</span>
                        <div class="flex items-baseline space-x-1 mt-0.5">
                            <span id="dc_close_qty" style="font-size: 20px; font-weight: 900; color: #78350f; font-family: monospace;">0</span>
                            <span style="font-size: 11px; font-weight: 700; color: #b45309;">pcs</span>
                        </div>
                        <span id="dc_qty_note" style="font-size: 10px; font-weight: 600; color: #b45309; display: block; margin-top: 2px;">Full Qty Kanban</span>
                    </div>

                    <div style="background-color: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 12px; padding: 12px;">
                        <span style="font-size: 10px; font-weight: 800; color: #166534; letter-spacing: 0.5px; text-transform: uppercase; display: block;">SAMPLE AQL (PASSED)</span>
                        <div class="flex items-baseline space-x-1 mt-0.5">
                            <span id="dc_sample_size" style="font-size: 20px; font-weight: 900; color: #14532d; font-family: monospace;">0</span>
                            <span style="font-size: 11px; font-weight: 700; color: #15803d;">pcs</span>
                        </div>
                        <span style="font-size: 10px; font-weight: 600; color: #15803d; display: block; margin-top: 2px;">Level II · 0 NG (Masuk PPM)</span>
                    </div>
                </div>

                <!-- Penjelasan Transparansi Audit -->
                <div style="background-color: #eff6ff; border: 1px solid #bfdbfe; border-radius: 10px; padding: 10px 12px;" class="flex items-start space-x-2.5 text-xs text-blue-900 leading-relaxed">
                    <svg style="width: 16px; height: 16px; color: #2563eb; flex-shrink: 0; margin-top: 2px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    <span style="font-size: 11px; color: #1e40af;">Sistem akan mencatat 1 Sesi Inspeksi Passed dengan 1 Lot Virtual agar <b>PPM Dashboard</b> dan riwayat audit mutu tetap 100% akurat.</span>
                </div>

                <!-- Input Catatan/Alasan Opsional -->
                <div>
                    <label for="dc_remarks" style="font-size: 11px; font-weight: 700; color: #334155; display: block; margin-bottom: 4px;">
                        Alasan / Keterangan <span style="font-weight: 400; color: #94a3b8;">(Opsional)</span>
                    </label>
                    <input type="text" id="dc_remarks" name="remarks" maxlength="150"
                           placeholder="Misal: Instruksi Chief QC / Urgent Shipment / Skip-Lot"
                           style="width: 100%; font-size: 12px; font-weight: 500; padding: 8px 12px; border-radius: 8px; border: 1px solid #cbd5e1; outline: none; transition: border-color 0.2s;"
                           onfocus="this.style.borderColor='#059669';" onblur="this.style.borderColor='#cbd5e1';">
                </div>

                <!-- Tombol Aksi -->
                <div style="border-top: 1px solid #f1f5f9; padding-top: 12px;" class="flex items-center justify-end space-x-2">
                    <button type="button" onclick="closeDirectCloseModal()" 
                            style="background-color: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; font-size: 12px; font-weight: 700; padding: 8px 16px; border-radius: 8px;"
                            class="hover:bg-slate-200 cursor-pointer transition-colors">
                        Batal
                    </button>
                    <button type="submit" id="btnSubmitDirectClose" 
                            style="background-color: #059669; color: #ffffff; font-size: 12px; font-weight: 800; padding: 8px 18px; border-radius: 8px; box-shadow: 0 1px 3px rgba(5, 150, 105, 0.4);"
                            class="hover:opacity-95 active:scale-95 inline-flex items-center gap-1.5 cursor-pointer transition-all">
                        <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                        <span>Ya, Selesaikan Langsung</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($isAdminOrSupervisor): ?>
    <!-- FLOATING BULK ACTION BOTTOM BAR (SOLID STYLING) -->
    <div id="bulk-kanban-action-bar" 
         style="display: none; position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%); z-index: 9998; background-color: #0f172a; color: #ffffff; border: 1px solid #334155; border-radius: 14px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.4), 0 8px 10px -6px rgba(0, 0, 0, 0.3); padding: 10px 18px;"
         class="items-center gap-4">
        <div class="flex items-center space-x-3">
            <span style="background-color: #2563eb; color: #ffffff; font-size: 11px; font-weight: 800; padding: 4px 10px; border-radius: 9999px;" class="tracking-wide">
                <span id="bulk-selected-count">0</span> KANBAN TERPILIH
            </span>
            <div class="text-xs text-slate-300 font-medium">
                Total Kuota: <span id="bulk-selected-qty" class="text-white font-mono font-bold text-sm">0 pcs</span>
            </div>
        </div>
        <div class="h-5 w-px bg-slate-700"></div>
        <div class="flex items-center space-x-2">
            <button type="button" onclick="clearBulkKanbanSelection()" 
                    style="background-color: #334155; color: #cbd5e1; border: 1px solid #475569; font-size: 11px; font-weight: 700; padding: 7px 12px; border-radius: 8px;"
                    class="hover:bg-slate-600 hover:text-white cursor-pointer transition-colors">
                Batal Pilih
            </button>
            <button type="button" onclick="openBulkDirectCloseModal()" 
                    style="background-color: #059669; color: #ffffff; border: 1px solid #047857; font-size: 12px; font-weight: 800; padding: 7px 16px; border-radius: 8px; box-shadow: 0 2px 4px rgba(5,150,105,0.4);"
                    class="hover:opacity-95 active:scale-95 inline-flex items-center gap-1.5 cursor-pointer transition-all">
                <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                <span>Close Terpilih Sekaligus</span>
            </button>
        </div>
    </div>

    <!-- MODAL BATCH DIRECT CLOSE KANBAN (ADMIN BULK) -->
    <div id="modalBulkDirectCloseKanban" 
         style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; width: 100vw; height: 100vh; background-color: rgba(15, 23, 42, 0.65); z-index: 99999; display: none; align-items: center; justify-content: center; padding: 16px; box-sizing: border-box;"
         onclick="if(event.target === this) closeBulkDirectCloseModal();">
        <div style="max-width: 560px; width: 100%; margin: auto; border: 1px solid #e2e8f0; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); border-radius: 16px; overflow: hidden; background-color: #ffffff;">
            
            <!-- Header Modal Solid Emerald -->
            <div style="background-color: #065f46; color: #ffffff; padding: 16px 22px;" class="flex items-center justify-between">
                <div class="flex items-center space-x-3">
                    <div style="width: 38px; height: 38px; background-color: rgba(255, 255, 255, 0.18); border: 1px solid rgba(255, 255, 255, 0.25);" class="rounded-xl flex items-center justify-center flex-shrink-0">
                        <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/>
                        </svg>
                    </div>
                    <div>
                        <h3 style="font-size: 15px; font-weight: 800; color: #ffffff; line-height: 1.2;">Batch Direct Close Kanban</h3>
                        <p style="font-size: 11px; color: #a7f3d0; margin-top: 2px;">Selesaikan banyak Kanban sekaligus tanpa scan fisik</p>
                    </div>
                </div>
                <button type="button" onclick="closeBulkDirectCloseModal()" style="color: #ffffff; background-color: rgba(255,255,255,0.15);" class="w-8 h-8 rounded-lg hover:opacity-80 flex items-center justify-center font-bold text-sm cursor-pointer transition-opacity">
                    ✕
                </button>
            </div>

            <!-- Body Form -->
            <form id="formBulkDirectCloseKanban" onsubmit="submitBulkDirectClose(event)" class="p-6 space-y-4">
                
                <!-- Ringkasan Batch -->
                <div style="background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 14px;" class="space-y-2">
                    <div class="flex items-center justify-between pb-2 border-b border-slate-200">
                        <span style="font-size: 11px; font-weight: 800; color: #475569; text-transform: uppercase;">Total Kanban Dipilih</span>
                        <span style="background-color: #059669; color: #ffffff; font-size: 11px; font-weight: 800; padding: 2px 10px; border-radius: 9999px;">
                            <span id="bulk-modal-total-count">0</span> Kanban
                        </span>
                    </div>
                    <div class="grid grid-cols-2 gap-3 pt-1">
                        <div style="background-color: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; padding: 8px 10px;">
                            <span style="font-size: 10px; font-weight: 800; color: #92400e; text-transform: uppercase; display: block;">KUOTA DITUTUP</span>
                            <div class="flex items-baseline space-x-1 mt-0.5">
                                <span id="bulk-modal-total-qty" style="font-size: 16px; font-weight: 900; color: #78350f; font-family: monospace;">0</span>
                                <span style="font-size: 10px; font-weight: 700; color: #b45309;">pcs</span>
                            </div>
                        </div>
                        <div style="background-color: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 8px 10px;">
                            <span style="font-size: 10px; font-weight: 800; color: #166534; text-transform: uppercase; display: block;">SAMPLE AQL (PASSED)</span>
                            <div class="flex items-baseline space-x-1 mt-0.5">
                                <span id="bulk-modal-total-sample" style="font-size: 16px; font-weight: 900; color: #14532d; font-family: monospace;">0</span>
                                <span style="font-size: 10px; font-weight: 700; color: #15803d;">pcs</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Daftar Kanban Yang Akan Ditutup -->
                <div>
                    <label style="font-size: 11px; font-weight: 700; color: #334155; display: block; margin-bottom: 6px;">
                        Daftar Kanban yang Diproses:
                    </label>
                    <div id="bulk-modal-items-list" style="max-height: 180px; overflow-y: auto; background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 8px;" class="space-y-1.5 custom-scrollbar">
                        <!-- Populated by JS -->
                    </div>
                </div>

                <!-- Info AQL & Dashboard Compliance -->
                <div style="background-color: #eff6ff; border: 1px solid #bfdbfe; border-radius: 10px; padding: 10px 12px;" class="flex items-start space-x-2.5 text-xs text-blue-900 leading-relaxed">
                    <svg style="width: 16px; height: 16px; color: #2563eb; flex-shrink: 0; margin-top: 2px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    <span style="font-size: 11px; color: #1e40af;">Setiap Kanban akan dibuatkan 1 Sesi & 1 Lot Virtual PASSED otomatis sesuai standar sampling AQL. Riwayat audit tercatat atas nama Anda (Admin).</span>
                </div>

                <!-- Input Catatan/Alasan Batch -->
                <div>
                    <label for="bulk_dc_remarks" style="font-size: 11px; font-weight: 700; color: #334155; display: block; margin-bottom: 4px;">
                        Alasan / Keterangan Batch <span style="font-weight: 400; color: #94a3b8;">(Opsional)</span>
                    </label>
                    <input type="text" id="bulk_dc_remarks" name="remarks" maxlength="150"
                           placeholder="Misal: Batch Direct Pass End-of-Shift / Urgent Shipment"
                           style="width: 100%; font-size: 12px; font-weight: 500; padding: 8px 12px; border-radius: 8px; border: 1px solid #cbd5e1; outline: none; transition: border-color 0.2s;"
                           onfocus="this.style.borderColor='#059669';" onblur="this.style.borderColor='#cbd5e1';">
                </div>

                <!-- Tombol Aksi -->
                <div style="border-top: 1px solid #f1f5f9; padding-top: 12px;" class="flex items-center justify-end space-x-2">
                    <button type="button" onclick="closeBulkDirectCloseModal()" 
                            style="background-color: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; font-size: 12px; font-weight: 700; padding: 8px 16px; border-radius: 8px;"
                            class="hover:bg-slate-200 cursor-pointer transition-colors">
                        Batal
                    </button>
                    <button type="submit" id="btnSubmitBulkDirectClose" 
                            style="background-color: #059669; color: #ffffff; font-size: 12px; font-weight: 800; padding: 8px 18px; border-radius: 8px; box-shadow: 0 1px 3px rgba(5, 150, 105, 0.4);"
                            class="hover:opacity-95 active:scale-95 inline-flex items-center gap-1.5 cursor-pointer transition-all">
                        <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                        <span>Selesaikan Semua Sekaligus</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
