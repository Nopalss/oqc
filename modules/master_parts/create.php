<?php
$breadcrumbCategory = "MASTER DATA";
$pageTitle = "Tambah Master Part Baru";
$pageSubtitle = "Tambahkan referensi Part Code dan Part Name baru ke dalam sistem";

require_once __DIR__ . '/../../layouts/header.php';
require_once __DIR__ . '/../../layouts/sidebar.php';

require_menu_access('master_parts');

$pdo = getDB();
$modelsList = [];
if ($pdo) {
    try {
        $modelsList = $pdo->query("SELECT * FROM master_models ORDER BY name ASC")->fetchAll();
    } catch (Exception $e) {}
}
?>

<div id="main-content-wrapper" class="flex-1 md:pl-64 flex flex-col min-h-screen transition-all duration-300">
    
    <?php require_once __DIR__ . '/../../layouts/navbar.php'; ?>

    <main class="flex-1 p-4 md:p-6 space-y-4 max-w-2xl">
        
        <?= render_flash() ?>

        <div class="flex items-center space-x-3 mb-2">
            <a href="<?= base_url('modules/master_parts/index.php') ?>" class="btn-secondary py-1 px-2.5 text-xs">
                &larr; Kembali
            </a>
            <h2 class="text-sm font-bold text-slate-800">Form Tambah Master Part</h2>
        </div>

        <div class="card">
            <form action="<?= base_url('modules/master_parts/store.php') ?>" method="POST" class="space-y-4">
                <?= csrf_field() ?>
                
                <!-- Part Code -->
                <div>
                    <label for="part_code" class="form-label">Part Code <span class="text-rose-500">*</span></label>
                    <input type="text" id="part_code" name="part_code" required class="form-input uppercase tracking-wider font-mono" placeholder="Contoh: PART-X100">
                    <p class="text-[10px] text-slate-400 mt-1">Part Code harus unik dan tidak boleh duplikat.</p>
                </div>

                <!-- Part Name -->
                <div>
                    <label for="part_name" class="form-label">Part Name / Description <span class="text-rose-500">*</span></label>
                    <input type="text" id="part_name" name="part_name" required class="form-input" placeholder="Contoh: Cover Hinge Bracket">
                </div>

                <!-- Model Part Dropdown with Live Search & Auto-Add New Model -->
                <div class="relative" id="model-combobox-wrapper">
                    <label for="model_search_input" class="form-label flex items-center justify-between">
                        <span>Model Produk</span>
                        <span id="model-status-badge" class="hidden text-[10px] font-semibold px-2 py-0.5 rounded tracking-wide"></span>
                    </label>

                    <div class="relative mt-1">
                        <!-- Input Search & Ketik Manual -->
                        <div class="relative flex items-center">
                            <input type="text" 
                                   id="model_search_input" 
                                   autocomplete="off" 
                                   class="form-input text-xs font-semibold pr-16 placeholder:text-slate-400 placeholder:font-normal uppercase" 
                                   placeholder="Ketik untuk mencari atau menambah model baru...">
                            
                            <div class="absolute right-2 flex items-center space-x-1">
                                <!-- Tombol Bersihkan / Clear -->
                                <button type="button" 
                                        id="btn-clear-model" 
                                        class="hidden text-slate-400 hover:text-slate-600 p-1 text-xs focus:outline-none" 
                                        title="Hapus Model">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                                </button>
                                <!-- Panah Dropdown -->
                                <button type="button" 
                                        id="btn-toggle-model-dropdown" 
                                        class="text-slate-400 hover:text-slate-600 p-1 text-xs focus:outline-none"
                                        title="Buka / Tutup Daftar">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                                </button>
                            </div>
                        </div>

                        <!-- Hidden Inputs untuk submit ke Server -->
                        <input type="hidden" name="model_id" id="model_id" value="">
                        <input type="hidden" name="model_name" id="model_name" value="">

                        <!-- Dropdown Options List -->
                        <div id="model-dropdown-list" 
                             class="hidden absolute z-50 left-0 right-0 mt-1 bg-white border border-slate-200 rounded-lg shadow-lg max-h-56 overflow-y-auto divide-y divide-slate-100 text-xs">
                            <!-- Opsi Tanpa Model -->
                            <div class="model-option p-2.5 hover:bg-slate-50 cursor-pointer flex items-center text-slate-500 font-medium" 
                                 data-id="" 
                                 data-name="">
                                <span class="italic text-slate-400">-- Tanpa Model / Kosongkan --</span>
                            </div>

                            <!-- List Models Terdaftar -->
                            <div id="existing-models-container" class="divide-y divide-slate-100">
                                <?php foreach ($modelsList as $m): ?>
                                    <div class="model-option p-2.5 hover:bg-blue-50 cursor-pointer flex items-center justify-between text-slate-700 font-semibold"
                                         data-id="<?= $m['id'] ?>" 
                                         data-name="<?= htmlspecialchars($m['name']) ?>">
                                        <span><?= htmlspecialchars($m['name']) ?></span>
                                        <span class="text-[10px] text-slate-400 font-normal">Model Terdaftar</span>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <!-- Opsi Model Baru (Muncul jika user ketik teks yang belum ada) -->
                            <div id="new-model-option" class="hidden p-2.5 bg-amber-50 hover:bg-amber-100/80 cursor-pointer border-t border-amber-200 text-amber-900 font-semibold">
                                <div class="flex items-center space-x-2">
                                    <span class="text-amber-600 font-bold text-sm leading-none">+</span>
                                    <div class="flex-1">
                                        <div class="text-[10px] text-amber-700 uppercase tracking-wider font-bold">Gunakan Sebagai Model Baru:</div>
                                        <div id="new-model-text" class="text-xs text-amber-900 font-bold tracking-wide"></div>
                                    </div>
                                    <span class="text-[10px] bg-amber-200 text-amber-800 px-1.5 py-0.5 rounded font-mono">Baru</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <p class="text-[10px] text-slate-400 mt-1">
                        Pilih dari daftar atau ketik langsung nama model baru jika belum ada. Sistem akan otomatis mendaftarkannya ke Master Model saat disimpan.
                    </p>
                </div>

                <!-- Level AQL Sampling Dropdown -->
                <div>
                    <label for="aql_level" class="form-label">Level Inspeksi AQL <span class="text-rose-500">*</span></label>
                    <select id="aql_level" name="aql_level" required class="form-input text-xs font-semibold">
                        <option value="G-I">G-I (Longgar / Reduced Inspection)</option>
                        <option value="G-II" selected>G-II (Normal / Standar STI Default)</option>
                        <option value="G-III">G-III (Ketat / Tightened Inspection)</option>
                    </select>
                    <p class="text-[10px] text-slate-400 mt-1">
                        Tingkat ketelitian sampling AQL G-II 0.4. Pilih G-III jika part memiliki riwayat NG tinggi untuk meningkatkan jumlah sampel pemeriksaan.
                    </p>
                </div>

                <!-- Submit Bar -->
                <div class="flex items-center justify-end space-x-2 pt-4 border-t border-slate-200">
                    <a href="<?= base_url('modules/master_parts/index.php') ?>" class="btn-secondary">Batal</a>
                    <button type="submit" class="btn-primary">Simpan Master Part</button>
                </div>

            </form>
        </div>

    </main>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const wrapper = document.getElementById('model-combobox-wrapper');
        const input = document.getElementById('model_search_input');
        const dropdown = document.getElementById('model-dropdown-list');
        const modelIdInput = document.getElementById('model_id');
        const modelNameInput = document.getElementById('model_name');
        const badge = document.getElementById('model-status-badge');
        const btnClear = document.getElementById('btn-clear-model');
        const btnToggle = document.getElementById('btn-toggle-model-dropdown');
        const newModelOption = document.getElementById('new-model-option');
        const newModelText = document.getElementById('new-model-text');
        const options = dropdown ? dropdown.querySelectorAll('.model-option') : [];

        if (!wrapper || !input || !dropdown) return;

        function updateBadge(type, text) {
            if (!text) {
                badge.classList.add('hidden');
                badge.classList.remove('bg-emerald-100', 'text-emerald-800', 'bg-amber-100', 'text-amber-800');
                badge.textContent = '';
                if (btnClear) btnClear.classList.add('hidden');
                return;
            }
            if (btnClear) btnClear.classList.remove('hidden');
            badge.classList.remove('hidden', 'bg-emerald-100', 'text-emerald-800', 'bg-amber-100', 'text-amber-800');
            if (type === 'existing') {
                badge.classList.add('bg-emerald-100', 'text-emerald-800');
                badge.textContent = 'Model Terdaftar';
            } else if (type === 'new') {
                badge.classList.add('bg-amber-100', 'text-amber-800');
                badge.textContent = 'Model Baru (Auto-Add)';
            }
        }

        function selectOption(id, name, isNew) {
            modelIdInput.value = id || '';
            modelNameInput.value = name || '';
            input.value = name || '';

            if (!name) {
                updateBadge(null);
            } else if (isNew) {
                updateBadge('new', name);
            } else {
                updateBadge('existing', name);
            }

            dropdown.classList.add('hidden');
        }

        function filterOptions() {
            const query = (input.value || '').trim().toUpperCase();
            let exactMatchFound = false;

            options.forEach(function(opt) {
                const optName = (opt.getAttribute('data-name') || '').trim().toUpperCase();
                if (!optName) {
                    opt.style.display = query ? 'none' : '';
                    return;
                }

                if (optName === query) {
                    exactMatchFound = true;
                }

                if (!query || optName.includes(query)) {
                    opt.style.display = '';
                } else {
                    opt.style.display = 'none';
                }
            });

            if (query !== '' && !exactMatchFound) {
                if (newModelText) newModelText.textContent = query;
                if (newModelOption) newModelOption.classList.remove('hidden');
            } else {
                if (newModelOption) newModelOption.classList.add('hidden');
            }
        }

        input.addEventListener('focus', function() {
            filterOptions();
            dropdown.classList.remove('hidden');
        });

        input.addEventListener('input', function() {
            filterOptions();
            dropdown.classList.remove('hidden');

            const currentVal = (input.value || '').trim().toUpperCase();
            if (currentVal) {
                if (btnClear) btnClear.classList.remove('hidden');
                modelNameInput.value = currentVal;
                modelIdInput.value = '';
                updateBadge('new', currentVal);
            } else {
                modelIdInput.value = '';
                modelNameInput.value = '';
                updateBadge(null);
            }
        });

        options.forEach(function(opt) {
            opt.addEventListener('click', function() {
                const id = this.getAttribute('data-id') || '';
                const name = this.getAttribute('data-name') || '';
                selectOption(id, name, false);
            });
        });

        if (newModelOption) {
            newModelOption.addEventListener('click', function() {
                const query = (input.value || '').trim().toUpperCase();
                if (query) {
                    selectOption('', query, true);
                }
            });
        }

        if (btnToggle) {
            btnToggle.addEventListener('click', function(e) {
                e.stopPropagation();
                if (dropdown.classList.contains('hidden')) {
                    filterOptions();
                    dropdown.classList.remove('hidden');
                    input.focus();
                } else {
                    dropdown.classList.add('hidden');
                }
            });
        }

        if (btnClear) {
            btnClear.addEventListener('click', function(e) {
                e.stopPropagation();
                selectOption('', '', false);
                input.focus();
            });
        }

        document.addEventListener('click', function(e) {
            if (!wrapper.contains(e.target)) {
                dropdown.classList.add('hidden');

                const query = (input.value || '').trim().toUpperCase();
                const curName = (modelNameInput.value || '').trim().toUpperCase();

                if (query !== '' && query !== curName) {
                    let foundId = null;
                    options.forEach(function(opt) {
                        const optName = (opt.getAttribute('data-name') || '').trim().toUpperCase();
                        if (optName && optName === query) {
                            foundId = opt.getAttribute('data-id');
                        }
                    });

                    if (foundId) {
                        selectOption(foundId, query, false);
                    } else {
                        selectOption('', query, true);
                    }
                } else if (query === '') {
                    selectOption('', '', false);
                }
            }
        });

        input.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                dropdown.classList.add('hidden');
            } else if (e.key === 'Enter') {
                if (!dropdown.classList.contains('hidden')) {
                    e.preventDefault();
                    const query = (input.value || '').trim().toUpperCase();

                    let matchedOpt = null;
                    options.forEach(function(opt) {
                        if (opt.style.display !== 'none') {
                            const optName = (opt.getAttribute('data-name') || '').trim().toUpperCase();
                            if (optName === query) {
                                matchedOpt = opt;
                            }
                        }
                    });

                    if (matchedOpt) {
                        matchedOpt.click();
                    } else if (newModelOption && !newModelOption.classList.contains('hidden')) {
                        newModelOption.click();
                    } else {
                        dropdown.classList.add('hidden');
                    }
                }
            }
        });

        // Inisialisasi awal nilai saat load
        const initialId = modelIdInput.value;
        const initialName = modelNameInput.value;
        if (initialName) {
            selectOption(initialId, initialName, !initialId);
        }
    });
    </script>

<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>
