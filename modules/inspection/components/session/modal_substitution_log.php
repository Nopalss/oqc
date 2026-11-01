<!-- Modal Rincian Penggantian Lot & Ref No -->
<div id="substitution-log-modal-overlay" style="position: fixed; inset: 0; background-color: rgba(15, 23, 42, 0.75); z-index: 99999; display: flex; align-items: center; justify-content: center; padding: 16px;" class="hidden">
    <div style="background-color: #ffffff; border-radius: 20px; max-width: 550px; width: 100%; max-height: 85vh; padding: 20px; border: 1px solid #cbd5e1; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25); margin: auto; display: flex; flex-direction: column; overflow: hidden;" class="animate-fadeIn space-y-3">
        
        <!-- Modal Header -->
        <div class="flex items-center justify-between border-b border-slate-200 pb-2.5 flex-shrink-0">
            <div class="flex items-center space-x-2.5">
                <div class="w-8 h-8 rounded-xl bg-emerald-100 text-emerald-700 flex items-center justify-center font-bold text-base shadow-2xs">
                    🔄
                </div>
                <div>
                    <h3 class="text-sm font-extrabold text-slate-900 leading-tight">Rincian Penggantian Lot &amp; Ref No</h3>
                    <p class="text-[11px] text-slate-500 font-medium">Histori pemetaan lot pengganti untuk sesi ini</p>
                </div>
            </div>
            <button type="button" onclick="closeSubstitutionLogModal()" class="w-8 h-8 rounded-lg hover:bg-slate-100 text-slate-400 hover:text-slate-600 font-bold text-xl flex items-center justify-center transition-all cursor-pointer">&times;</button>
        </div>

        <!-- Modal Body Content -->
        <div id="modal-subst-log-list-body" style="display: flex; flex-direction: column; gap: 8px; max-height: 55vh; overflow-y: auto; padding-right: 2px;" class="flex-1 min-h-0">
            <!-- Populated via JS -->
        </div>

        <!-- Modal Footer -->
        <div class="pt-2 border-t border-slate-100 flex justify-end flex-shrink-0">
            <button type="button" onclick="closeSubstitutionLogModal()" class="px-4 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold text-xs rounded-lg transition-all cursor-pointer">
                Tutup
            </button>
        </div>
    </div>
</div>

