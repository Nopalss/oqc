function openLotNgModal(lotId, lotNumber, refNumber, qty, sampleSize, rejectNumber) {
    closeAllLotDefectDropdowns();
    var modal = document.getElementById('modal-lot-defect');
    if (!modal) return;

    document.getElementById('modal-defect-lot-id').value = lotId;
    document.getElementById('modal-defect-reject-num').value = rejectNumber;
    if (document.getElementById('modal-defect-sample-size')) {
        document.getElementById('modal-defect-sample-size').value = sampleSize;
    }
    
    var subtitle = 'Lot #' + lotNumber + (refNumber ? ' | Ref: ' + refNumber : '') + ' (' + Number(qty).toLocaleString() + ' pcs)';
    document.getElementById('modal-defect-lot-subtitle').textContent = subtitle;
    
    document.getElementById('modal-defect-aql-text').textContent = 'AQL: G-II | Wajib Sample: ' + sampleSize + ' pcs | Batas NG: ' + rejectNumber;

    var container = document.getElementById('modal-defect-units-container');
    if (container) container.innerHTML = '';

    // Ambil data defect yang sudah ada jika sedang mode edit / tambah NG
    var sessionData = window.lastWorkbenchSessionData;
    var existingDefects = [];
    if (sessionData) {
        if (sessionData.ng_records_by_lot && sessionData.ng_records_by_lot[lotId]) {
            existingDefects = sessionData.ng_records_by_lot[lotId];
        } else if (sessionData.ng_records) {
            existingDefects = sessionData.ng_records.filter(function(r) { return parseInt(r.session_lot_id) === parseInt(lotId); });
        }
    }

    var activeExisting = existingDefects.filter(function(d) {
        return (!d.is_cancelled || parseInt(d.is_cancelled) === 0) && (!d.is_sorted || parseInt(d.is_sorted) === 0);
    });

    if (activeExisting.length > 0) {
        activeExisting.forEach(function(d) {
            addLotDefectRow(d);
        });
    } else {
        addLotDefectRow(null);
    }

    modal.style.display = 'flex';
}

function closeLotNgModal() {
    closeAllLotDefectDropdowns();
    var modal = document.getElementById('modal-lot-defect');
    if (modal) modal.style.display = 'none';
}

function addLotDefectRow(defectData) {
    var container = document.getElementById('modal-defect-units-container');
    if (!container) return;

    var emptyMsg = document.getElementById('modal-defect-empty-units');
    if (emptyMsg) emptyMsg.remove();

    var rowIdx = container.querySelectorAll('.lot-defect-row-card').length + 1;
    var card = document.createElement('div');
    card.className = 'lot-defect-row-card p-3 bg-white border border-slate-200 rounded-xl space-y-2 shadow-2xs';

    var dtId = defectData ? (defectData.defect_type_id ? parseInt(defectData.defect_type_id) : 0) : 0;
    var dtName = defectData ? (defectData.defect_name || defectData.defect_type_name || '') : '';
    var dtCode = defectData ? (defectData.defect_code || '') : '';
    var isCustom = defectData ? (defectData.remark && defectData.remark.indexOf('Custom:') === 0) : false;
    var qtyNg = defectData ? (parseInt(defectData.qty_ng) || 1) : 1;

    card.innerHTML = 
        '<div style="display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid #f1f5f9; padding-bottom: 6px;">' +
            '<div style="display: flex; align-items: center; gap: 6px;">' +
                '<span style="font-size: 13px;">⚠️</span>' +
                '<span class="row-defect-title font-extrabold text-slate-800 text-xs">Temuan Defect #' + rowIdx + '</span>' +
            '</div>' +
            '<button type="button" class="btn-remove-defect-row" onclick="removeLotDefectRow(this)" style="background: none; border: none; font-size: 11px; font-weight: 700; color: #e11d48; cursor: pointer; padding: 2px 4px; display: inline-flex; align-items: center; gap: 3px;" title="Hapus jenis defect ini">' +
                '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>' +
                '<span>Hapus</span>' +
            '</button>' +
        '</div>' +
        '<div style="display: flex; align-items: center; gap: 8px;" class="lot-combobox-wrap">' +
            '<div style="flex: 1; position: relative;">' +
                '<input type="hidden" class="lot-ng-defect-type" value="' + (isCustom ? 'custom' : (dtId || '')) + '">' +
                '<div style="position: relative; display: flex; align-items: center;">' +
                    '<input type="text" class="lot-defect-search-input" ' +
                           'placeholder="Ketik kode atau nama defect... (contoh: OS, Baret)" ' +
                           'autocomplete="off" ' +
                           'onfocus="openLotDefectDropdown(this)" ' +
                           'oninput="filterLotDefectDropdown(this)" ' +
                           'onkeydown="navigateLotDefectDropdown(event, this)" ' +
                           'value="' + (isCustom ? 'Defect Lainnya (Manual)' : (dtCode ? ('[' + dtCode + '] ' + dtName) : dtName)) + '" ' +
                           'style="width: 100%; height: 34px; padding: 5px 30px 5px 10px; font-size: 11.5px; font-weight: 700; border: 1.5px solid #cbd5e1; border-radius: 8px; background-color: #ffffff; outline: none; transition: border-color 0.15s; box-sizing: border-box;">' +
                    '<button type="button" class="btn-combobox-toggle" onclick="toggleLotDefectDropdown(this)" tabindex="-1" ' +
                            'style="position: absolute; right: 5px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; color: #94a3b8; padding: 3px; display: flex; align-items: center; justify-content: center;">' +
                        '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>' +
                    '</button>' +
                '</div>' +
                '<div class="lot-defect-dropdown-list hidden" ' +
                     'style="display: none; position: fixed; z-index: 999999; background: #ffffff; border: 1.5px solid #cbd5e1; border-radius: 9px; box-shadow: 0 12px 28px -4px rgba(0,0,0,0.18); max-height: 190px; overflow-y: auto; box-sizing: border-box;">' +
                '</div>' +
                '<input type="text" class="lot-ng-custom-name ' + (isCustom ? '' : 'hidden') + '" placeholder="Ketik nama defect manual..." ' +
                       'value="' + (isCustom && defectData.remark ? escapeHtml(defectData.remark.replace(/^Custom:\s*/, '')) : (isCustom ? escapeHtml(dtName) : '')) + '" ' +
                       'style="width: 100%; height: 32px; padding: 5px 10px; font-size: 11.5px; border: 1.5px solid #fca5a5; border-radius: 8px; background-color: #fff1f2; color: #9f1239; outline: none; font-weight: 600; display: ' + (isCustom ? 'block' : 'none') + '; box-sizing: border-box; margin-top: 4px;">' +
            '</div>' +
            '<div style="display: flex; align-items: center; gap: 4px; border: 1.5px solid #cbd5e1; border-radius: 8px; padding: 2px 6px; background-color: #f8fafc; height: 34px; box-sizing: border-box; flex-shrink: 0;">' +
                '<span style="font-size: 10px; font-weight: 800; color: #64748b; margin-right: 2px;">Qty:</span>' +
                '<button type="button" onclick="changeDefectRowQty(this, -1)" style="width: 22px; height: 24px; border-radius: 5px; background: #e2e8f0; color: #1e293b; font-weight: 800; font-size: 12px; border: none; cursor: pointer; display: flex; align-items: center; justify-content: center;">-</button>' +
                '<input type="number" class="lot-ng-row-qty" value="' + qtyNg + '" min="1" oninput="updateLotDefectTotalDisplay()" onchange="updateLotDefectTotalDisplay()" style="width: 42px; text-align: center; font-family: monospace; font-size: 12px; font-weight: 900; color: #be123c; border: none; background: transparent; outline: none; padding: 0;">' +
                '<button type="button" onclick="changeDefectRowQty(this, 1)" style="width: 22px; height: 24px; border-radius: 5px; background: #e2e8f0; color: #1e293b; font-weight: 800; font-size: 12px; border: none; cursor: pointer; display: flex; align-items: center; justify-content: center;">+</button>' +
                '<span style="font-size: 10px; font-weight: 700; color: #64748b; margin-left: 1px;">pcs</span>' +
            '</div>' +
        '</div>';

    container.appendChild(card);
    updateLotDefectTotalDisplay();
}

function removeLotDefectRow(btn) {
    closeAllLotDefectDropdowns();
    var card = btn.closest('.lot-defect-row-card');
    if (card) {
        card.remove();
        var container = document.getElementById('modal-defect-units-container');
        if (container && container.querySelectorAll('.lot-defect-row-card').length === 0) {
            container.innerHTML = 
                '<div id="modal-defect-empty-units" style="padding: 22px 16px; text-align: center; background: #fff1f2; border: 1.5px dashed #fca5a5; border-radius: 12px; margin: 4px 0;">' +
                    '<div style="font-size: 26px; margin-bottom: 5px;">🗑️</div>' +
                    '<div style="font-size: 13px; font-weight: 800; color: #9f1239;">Semua Temuan Defect Telah Dihapus</div>' +
                    '<div style="font-size: 11px; color: #be123c; margin-top: 3px;">Jika disimpan, box ini akan dikembalikan ke status <b>PASSED (Normal / 0 NG)</b>.</div>' +
                    '<div style="margin-top: 10px;">' +
                        '<button type="button" onclick="addLotDefectRow()" style="padding: 5px 12px; font-size: 11px; font-weight: 700; color: #0284c7; background: #ffffff; border: 1px solid #7dd3fc; border-radius: 7px; cursor: pointer; display: inline-flex; align-items: center; gap: 4px;">' +
                            '<span>+ Tambah Jenis Defect Baru</span>' +
                        '</button>' +
                    '</div>' +
                '</div>';
        }
        updateLotDefectTotalDisplay();
    }
}

function changeDefectRowQty(btn, delta) {
    var card = btn.closest('.lot-defect-row-card');
    if (!card) return;
    var input = card.querySelector('.lot-ng-row-qty');
    if (input) {
        var val = (parseInt(input.value) || 1) + delta;
        input.value = Math.max(1, val);
        updateLotDefectTotalDisplay();
    }
}

// ── Searchable Combobox Core Functions for Lot Defect Selection ────────
function positionLotDefectDropdown(wrapEl) {
    if (!wrapEl) return;
    var inputEl = wrapEl.querySelector('.lot-defect-search-input');
    var listEl = wrapEl.querySelector('.lot-defect-dropdown-list');
    if (!inputEl || !listEl) return;

    var rect = inputEl.getBoundingClientRect();
    listEl.style.position = 'fixed';
    listEl.style.left = rect.left + 'px';
    listEl.style.width = rect.width + 'px';
    listEl.style.zIndex = '999999';

    var spaceBelow = window.innerHeight - rect.bottom;
    var maxListHeight = 190;
    if (spaceBelow < (maxListHeight + 10) && rect.top > (maxListHeight + 10)) {
        listEl.style.top = 'auto';
        listEl.style.bottom = (window.innerHeight - rect.top + 4) + 'px';
    } else {
        listEl.style.top = (rect.bottom + 4) + 'px';
        listEl.style.bottom = 'auto';
    }
}

function populateLotDefectDropdown(wrapEl, filterText) {
    var listEl = wrapEl.querySelector('.lot-defect-dropdown-list');
    if (!listEl) return;
    
    var container = document.getElementById('modal-defect-units-container');
    var alreadyChosenIds = [];
    if (container) {
        var currHidden = wrapEl.querySelector('.lot-ng-defect-type');
        container.querySelectorAll('.lot-ng-defect-type').forEach(function(h) {
            if (h !== currHidden && h.value && h.value !== 'custom') {
                alreadyChosenIds.push(String(h.value));
            }
        });
    }

    var query = (filterText || '').trim().toLowerCase();
    var html = '';
    var matchCount = 0;

    if (typeof DEFECT_TYPES_LIST !== 'undefined' && DEFECT_TYPES_LIST.length > 0) {
        DEFECT_TYPES_LIST.forEach(function(dt) {
            var code = (dt.code || '').trim();
            var name = (dt.name || '').trim();
            var codeLower = code.toLowerCase();
            var nameLower = name.toLowerCase();

            if (!query || codeLower.indexOf(query) !== -1 || nameLower.indexOf(query) !== -1) {
                matchCount++;
                var isAlready = alreadyChosenIds.indexOf(String(dt.id)) !== -1;
                var safeCode = escapeHtml(code);
                var safeName = escapeHtml(name);

                if (isAlready) {
                    html += '<div class="lot-defect-option-item" style="padding: 7px 12px; display: flex; align-items: center; border-bottom: 1px solid #f1f5f9; background-color: #f8fafc; opacity: 0.55; cursor: not-allowed;" title="Defect ini sudah dipilih pada baris lain">';
                    if (code) {
                        html += '<span style="font-family: monospace; font-size: 11px; font-weight: 700; background: #e2e8f0; color: #64748b; border: 1px solid #cbd5e1; padding: 2px 6px; border-radius: 4px; margin-right: 8px; flex-shrink: 0;">[' + safeCode + ']</span>';
                    }
                    html += '<span style="font-size: 11.5px; font-weight: 600; color: #94a3b8; text-decoration: line-through;">' + safeName + '</span>';
                    html += '<span style="margin-left: auto; font-size: 9.5px; font-weight: 800; background: #fef2f2; color: #ef4444; border: 1px solid #fecaca; padding: 1px 6px; border-radius: 4px; flex-shrink: 0;">Sudah ada</span>';
                    html += '</div>';
                } else {
                    html += '<div class="lot-defect-option-item" onclick="selectLotDefectOption(this, \'' + dt.id + '\', \'' + addslashesJs(code) + '\', \'' + addslashesJs(name) + '\')" ' +
                            'style="padding: 8px 12px; cursor: pointer; display: flex; align-items: center; border-bottom: 1px solid #f1f5f9; transition: background 0.1s;" ' +
                            'onmouseover="this.style.backgroundColor=\'#eff6ff\'" onmouseout="if(this.dataset.active !== \'true\') this.style.backgroundColor=\'#ffffff\'">';
                    if (code) {
                        html += '<span style="font-family: monospace; font-size: 11px; font-weight: 800; background: #dbeafe; color: #1e40af; border: 1px solid #bfdbfe; padding: 2px 6px; border-radius: 4px; margin-right: 8px; flex-shrink: 0;">[' + safeCode + ']</span>';
                    }
                    html += '<span style="font-size: 12px; font-weight: 700; color: #1e293b; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">' + safeName + '</span>';
                    html += '</div>';
                }
            }
        });
    }

    if (matchCount === 0 && query) {
        html += '<div style="padding: 10px 12px; font-size: 12px; color: #94a3b8; font-style: italic; text-align: center;">Tidak ada jenis defect yang cocok dengan "' + escapeHtml(query) + '"</div>';
    }

    // Manual custom option at bottom
    html += '<div class="lot-defect-option-item" onclick="selectLotDefectOption(this, \'custom\', \'\', \'Defect Lainnya (Ketik Manual)\')" ' +
            'style="padding: 9px 12px; cursor: pointer; display: flex; align-items: center; gap: 8px; background: #fff1f2; color: #be123c; font-size: 12px; font-weight: 800; border-top: 1px solid #fecdd3;" ' +
            'onmouseover="this.style.background=\'#ffe4e6\'" onmouseout="this.style.background=\'#fff1f2\'">' +
            '<span style="font-weight: 900; font-size: 14px;">+</span>' +
            '<span>Defect Lainnya (Ketik Manual)...</span>' +
            '</div>';

    listEl.innerHTML = html;
}

function selectLotDefectOption(itemEl, id, code, name) {
    var wrapEl = itemEl.closest('.lot-combobox-wrap');
    if (!wrapEl) return;
    
    var container = document.getElementById('modal-defect-units-container');
    var hiddenInput = wrapEl.querySelector('.lot-ng-defect-type');
    var searchInput = wrapEl.querySelector('.lot-defect-search-input');
    var listEl = wrapEl.querySelector('.lot-defect-dropdown-list');
    var card = wrapEl.closest('.lot-defect-row-card');

    // Real-time protection: prevent duplicate defect type across rows
    if (container && id !== 'custom') {
        var existingSelected = false;
        container.querySelectorAll('.lot-ng-defect-type').forEach(function(h) {
            if (h !== hiddenInput && h.value === String(id)) {
                existingSelected = true;
            }
        });
        if (existingSelected) {
            if (listEl) {
                listEl.style.display = 'none';
                listEl.classList.add('hidden');
            }
            Swal.fire({
                icon: 'warning',
                title: 'Defect Sudah Ada',
                text: 'Jenis defect "' + (code ? '[' + code + '] ' : '') + name + '" sudah dipilih pada baris lain. Untuk menambah jumlah pcs, ubah Qty pada baris tersebut.',
                timer: 2200
            });
            return;
        }
    }

    if (hiddenInput) hiddenInput.value = id;
    
    if (searchInput) {
        if (id === 'custom') {
            searchInput.value = '+ Defect Lainnya (Ketik Manual)';
            searchInput.style.color = '#be123c';
        } else {
            searchInput.value = (code ? '[' + code + '] ' : '') + name;
            searchInput.style.color = '#0f172a';
        }
    }

    if (listEl) {
        listEl.style.display = 'none';
        listEl.classList.add('hidden');
    }

    if (card) {
        var customInput = card.querySelector('.lot-ng-custom-name');
        if (id === 'custom') {
            if (customInput) {
                customInput.classList.remove('hidden');
                customInput.style.display = 'block';
                customInput.focus();
            }
        } else {
            if (customInput) {
                customInput.classList.add('hidden');
                customInput.style.display = 'none';
                customInput.value = '';
            }
        }
    }

    updateLotDefectTotalDisplay();
}

function openLotDefectDropdown(inputEl) {
    var wrapEl = inputEl.closest('.lot-combobox-wrap');
    if (!wrapEl) return;
    
    closeAllLotDefectDropdowns(wrapEl);

    var listEl = wrapEl.querySelector('.lot-defect-dropdown-list');
    if (listEl) {
        populateLotDefectDropdown(wrapEl, inputEl.value);
        listEl.style.display = 'block';
        listEl.classList.remove('hidden');
        positionLotDefectDropdown(wrapEl);
    }
}

function filterLotDefectDropdown(inputEl) {
    var wrapEl = inputEl.closest('.lot-combobox-wrap');
    if (!wrapEl) return;
    
    var hiddenInput = wrapEl.querySelector('.lot-ng-defect-type');
    if (hiddenInput && hiddenInput.value !== 'custom') {
        hiddenInput.value = '';
    }

    var listEl = wrapEl.querySelector('.lot-defect-dropdown-list');
    if (listEl) {
        populateLotDefectDropdown(wrapEl, inputEl.value);
        listEl.style.display = 'block';
        listEl.classList.remove('hidden');
        positionLotDefectDropdown(wrapEl);
    }
}

function toggleLotDefectDropdown(btnEl) {
    var wrapEl = btnEl.closest('.lot-combobox-wrap');
    if (!wrapEl) return;
    var inputEl = wrapEl.querySelector('.lot-defect-search-input');
    var listEl = wrapEl.querySelector('.lot-defect-dropdown-list');
    if (!listEl) return;

    if (listEl.style.display === 'block') {
        listEl.style.display = 'none';
        listEl.classList.add('hidden');
    } else {
        closeAllLotDefectDropdowns(wrapEl);
        populateLotDefectDropdown(wrapEl, inputEl ? inputEl.value : '');
        listEl.style.display = 'block';
        listEl.classList.remove('hidden');
        positionLotDefectDropdown(wrapEl);
        if (inputEl) inputEl.focus();
    }
}

function closeAllLotDefectDropdowns(exceptWrap) {
    document.querySelectorAll('.lot-combobox-wrap').forEach(function(wrap) {
        if (exceptWrap && wrap === exceptWrap) return;
        var list = wrap.querySelector('.lot-defect-dropdown-list');
        if (list) {
            list.style.display = 'none';
            list.classList.add('hidden');
        }
    });
}

function navigateLotDefectDropdown(e, inputEl) {
    var wrapEl = inputEl.closest('.lot-combobox-wrap');
    if (!wrapEl) return;
    var listEl = wrapEl.querySelector('.lot-defect-dropdown-list');
    if (!listEl || listEl.style.display !== 'block') {
        if (e.key === 'ArrowDown') {
            openLotDefectDropdown(inputEl);
            e.preventDefault();
        } else if (e.key === 'Enter') {
            e.preventDefault();
            // Dropdown tertutup -> User menekan Enter untuk mengirimkan form temuan NG
            submitLotDefectForm();
        }
        return;
    }

    var items = listEl.querySelectorAll('.lot-defect-option-item');
    if (items.length === 0) return;

    var activeIdx = -1;
    for (var i = 0; i < items.length; i++) {
        if (items[i].dataset.active === 'true') {
            activeIdx = i;
            break;
        }
    }

    if (e.key === 'ArrowDown') {
        e.preventDefault();
        var nextIdx = activeIdx + 1;
        if (nextIdx >= items.length) nextIdx = 0;
        setLotDefectActiveItem(items, nextIdx);
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        var prevIdx = activeIdx - 1;
        if (prevIdx < 0) prevIdx = items.length - 1;
        setLotDefectActiveItem(items, prevIdx);
    } else if (e.key === 'Enter') {
        e.preventDefault();
        if (activeIdx >= 0 && items[activeIdx]) {
            items[activeIdx].click();
        } else if (items.length > 0) {
            items[0].click();
        }
    } else if (e.key === 'Escape') {
        e.preventDefault();
        listEl.style.display = 'none';
        listEl.classList.add('hidden');
    }
}

function setLotDefectActiveItem(items, idx) {
    items.forEach(function(it, i) {
        if (i === idx) {
            it.dataset.active = 'true';
            it.style.backgroundColor = '#dbeafe';
            it.scrollIntoView({ block: 'nearest' });
        } else {
            it.dataset.active = 'false';
            it.style.backgroundColor = '';
        }
    });
}

function addslashesJs(str) {
    if (!str) return '';
    return String(str).replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/"/g, '\\"');
}

// Global click-outside listener to close any combobox dropdown
document.addEventListener('click', function(e) {
    if (!e.target.closest('.lot-combobox-wrap') && !e.target.closest('.lot-defect-dropdown-list')) {
        closeAllLotDefectDropdowns(null);
    }
});

// Close combobox dropdown if window resized
window.addEventListener('resize', function() {
    closeAllLotDefectDropdowns(null);
});

// Close combobox dropdown if modal body is scrolled
document.addEventListener('DOMContentLoaded', function() {
    var mb = document.getElementById('modal-defect-body');
    if (mb) {
        mb.addEventListener('scroll', function() {
            closeAllLotDefectDropdowns(null);
        });
    }
});

function updateLotDefectTotalDisplay() {
    var container = document.getElementById('modal-defect-units-container');
    if (!container) return;

    var defectCards = container.querySelectorAll('.lot-defect-row-card');
    var totalTypes = defectCards.length;
    var totalPcsNg = 0;

    defectCards.forEach(function(card, idx) {
        var titleEl = card.querySelector('.row-defect-title');
        if (titleEl) titleEl.textContent = 'Temuan Defect #' + (idx + 1);

        var qtyInput = card.querySelector('.lot-ng-row-qty');
        if (qtyInput) {
            totalPcsNg += Math.max(1, parseInt(qtyInput.value) || 1);
        }
    });

    var dispUnits = document.getElementById('modal-defect-units-count');
    if (dispUnits) dispUnits.textContent = totalPcsNg;

    var dispDefects = document.getElementById('modal-defect-defects-count');
    if (dispDefects) dispDefects.textContent = totalTypes;

    var rejNum = parseInt(document.getElementById('modal-defect-reject-num').value) || 1;
    var verdictEl = document.getElementById('modal-defect-verdict-display');
    var submitBtn = document.getElementById('btn-submit-lot-ng');

    if (verdictEl) {
        if (totalTypes === 0 || totalPcsNg === 0) {
            verdictEl.textContent = 'STATUS: AKAN PASSED (0 NG)';
            verdictEl.style.backgroundColor = '#059669';
        } else if (totalPcsNg >= rejNum) {
            verdictEl.textContent = 'STATUS: REJECTED';
            verdictEl.style.backgroundColor = '#be123c';
        } else {
            verdictEl.textContent = 'STATUS: DI BAWAH BATAS';
            verdictEl.style.backgroundColor = '#d97706';
        }
    }

    if (submitBtn) {
        if (totalTypes === 0 || totalPcsNg === 0) {
            submitBtn.textContent = 'Simpan (Ubah Jadi PASSED)';
            submitBtn.style.backgroundColor = '#059669';
        } else {
            submitBtn.textContent = 'Simpan Temuan NG (REJECTED)';
            submitBtn.style.backgroundColor = '#e11d48';
        }
    }
}

function submitLotDefectForm() {
    var lotId = document.getElementById('modal-defect-lot-id').value;
    if (!lotId) return;

    var container = document.getElementById('modal-defect-units-container');
    if (!container) return;

    var defectCards = container.querySelectorAll('.lot-defect-row-card');
    if (defectCards.length === 0) {
        Swal.fire({
            title: 'Hapus Semua Temuan NG?',
            text: 'Semua jenis defect telah dihapus. Box ini akan dikembalikan ke status PASSED (Normal dengan 0 NG). Lanjutkan?',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#059669',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Ya, Jadikan PASSED',
            cancelButtonText: 'Batal'
        }).then(function(dialogRes) {
            if (!dialogRes.isConfirmed) return;

            Swal.fire({
                title: 'Mengubah Status ke PASSED...',
                allowOutsideClick: false,
                didOpen: function() { Swal.showLoading(); }
            });

            var payload = {
                session_lot_id: parseInt(lotId),
                action: 'passed'
            };

            fetch(window.OQC_CONFIG.baseUrl + "/modules/inspection/api/submit_lot_result.php", {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                closeLotNgModal();
                if (!res.success) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Gagal Menyimpan',
                        text: res.message || 'Terjadi kesalahan pada server'
                    });
                    return;
                }
                advanceFocusBoxAfterAction(lotId);
                if (res.session_status === 'passed') {
                    if (typeof Swal !== 'undefined' && Swal.isVisible()) Swal.close();
                    loadWorkbenchSessionData(currentSessionId, function(data) {
                        handleSessionFinishedModalFlow(data);
                    });
                } else {
                    Swal.fire({
                        icon: 'success',
                        title: 'Box Dinyatakan PASSED',
                        text: 'Seluruh temuan NG dibatalkan. Box kembali berstatus PASSED.',
                        timer: 1400,
                        showConfirmButton: false
                    }).then(function() {
                        loadWorkbenchSessionData(currentSessionId);
                    });
                }
            })
            .catch(function(err) {
                Swal.fire({
                    icon: 'error',
                    title: 'Koneksi Error',
                    text: 'Gagal terhubung ke server!'
                });
            });
        });
        return;
    }

    var defects = [];
    var totalPcsNg = 0;
    var selectedKeys = [];

    for (var i = 0; i < defectCards.length; i++) {
        var card = defectCards[i];
        var selectEl = card.querySelector('.lot-ng-defect-type');
        var customInput = card.querySelector('.lot-ng-custom-name');
        var qtyInput = card.querySelector('.lot-ng-row-qty');

        var defectVal = selectEl ? selectEl.value : '';
        var customName = (customInput && customInput.value) ? customInput.value.trim() : '';
        var rowQty = Math.max(1, parseInt(qtyInput ? qtyInput.value : 1) || 1);

        if (!defectVal) {
            Swal.fire({
                icon: 'warning',
                title: 'Pilih Jenis Defect',
                text: 'Harap pilih jenis defect pada baris temuan #' + (i + 1) + '!'
            });
            return;
        }

        if (defectVal === 'custom' && !customName) {
            Swal.fire({
                icon: 'warning',
                title: 'Nama Defect Manual Wajib Diisi',
                text: 'Harap ketik nama jenis defect manual pada baris temuan #' + (i + 1) + '!'
            });
            return;
        }

        var key = (defectVal === 'custom') ? ('custom:' + customName.toLowerCase()) : defectVal;
        if (selectedKeys.indexOf(key) !== -1) {
            Swal.fire({
                icon: 'warning',
                title: 'Jenis Defect Ganda',
                text: 'Jenis defect yang sama dipilih pada baris temuan #' + (i + 1) + '. Harap gabungkan Qty pada 1 baris saja!'
            });
            return;
        }
        selectedKeys.push(key);

        defects.push({
            unit_number: 1,
            defect_type_id: (defectVal === 'custom') ? 0 : parseInt(defectVal),
            custom_name: customName,
            qty_ng: rowQty
        });

        totalPcsNg += rowQty;
    }

    Swal.fire({
        title: 'Menyimpan Catatan Defect...',
        allowOutsideClick: false,
        didOpen: function() { Swal.showLoading(); }
    });

    var payload = {
        session_lot_id: parseInt(lotId),
        action: 'rejected',
        physical_ng_qty: totalPcsNg,
        defects: defects
    };

    fetch(window.OQC_CONFIG.baseUrl + "/modules/inspection/api/submit_lot_result.php", {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        closeLotNgModal();
        if (!res.success) {
            Swal.fire({
                icon: 'error',
                title: 'Gagal Menyimpan',
                text: res.message || 'Terjadi kesalahan pada server'
            });
            return;
        }

        advanceFocusBoxAfterAction(lotId);

        Swal.fire({
            icon: 'error',
            title: 'Lot Dinyatakan REJECTED',
            text: 'Temuan defect (' + totalPcsNg + ' pcs) telah dicatat dan lot ditandai REJECTED.',
            confirmButtonColor: '#e11d48',
            confirmButtonText: 'OK',
            timer: 1400
        }).then(function() {
            loadWorkbenchSessionData(currentSessionId);
        });
    })
    .catch(function(err) {
        Swal.fire({
            icon: 'error',
            title: 'Koneksi Error',
            text: 'Gagal terhubung ke server!'
        });
    });
}

function confirmLotPassed(lotId, lotNumber, refNumber, sampleSize) {
    if (!lotId) return;

    var lotLabel = '#' + lotNumber + (refNumber ? ' (Ref: ' + refNumber + ')' : '');

    Swal.fire({
        title: 'Konfirmasi Lolos (PASSED)?',
        html: 'Sampel fisik sebanyak <b>' + sampleSize + ' pcs</b> pada Lot <b>' + escapeHtml(lotLabel) + '</b> telah diperiksa seluruhnya dan <b>TIDAK ADA DEFECT (OK)</b>.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#059669',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Ya, Selesai Inspeksi (PASSED)',
        cancelButtonText: 'Batal',
        focusConfirm: true
    }).then(function(result) {
        if (!result.isConfirmed) return;

        Swal.fire({
            title: 'Menyimpan Hasil Lot...',
            allowOutsideClick: false,
            didOpen: function() { Swal.showLoading(); }
        });

        var payload = {
            session_lot_id: parseInt(lotId),
            action: 'passed'
        };

        fetch(window.OQC_CONFIG.baseUrl + "/modules/inspection/api/submit_lot_result.php", {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (!res.success) {
                Swal.fire({
                    icon: 'error',
                    title: 'Gagal',
                    text: res.message || 'Terjadi kesalahan'
                });
                return;
            }

            advanceFocusBoxAfterAction(lotId);

            if (res.session_status === 'passed') {
                if (typeof Swal !== 'undefined' && Swal.isVisible()) Swal.close();
                loadWorkbenchSessionData(currentSessionId, function(data) {
                    handleSessionFinishedModalFlow(data);
                });
            } else {
                Swal.fire({
                    icon: 'success',
                    title: 'Lot PASSED!',
                    text: 'Lot ' + lotNumber + ' berhasil dinyatakan lolos inspeksi.',
                    timer: 1200,
                    showConfirmButton: false
                });
                loadWorkbenchSessionData(currentSessionId);
            }
        })
        .catch(function(err) {
            Swal.fire({
                icon: 'error',
                title: 'Koneksi Error',
                text: 'Gagal terhubung ke server!'
            });
        });
    });
}

function resetLotToInProgress(lotId, lotNumber) {
    if (!lotId) return;

    Swal.fire({
        title: 'Kembalikan ke Pemeriksaan?',
        html: 'Status Lot <b>#' + escapeHtml(lotNumber) + '</b> akan dikembalikan ke status <b>Sedang Diinspeksi</b> agar dapat diperiksa ulang.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#2563eb',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Ya, Periksa Ulang',
        cancelButtonText: 'Batal'
    }).then(function(res) {
        if (!res.isConfirmed) return;

        Swal.fire({
            title: 'Memproses...',
            allowOutsideClick: false,
            didOpen: function() { Swal.showLoading(); }
        });

        var payload = {
            session_lot_id: parseInt(lotId),
            action: 'reset'
        };

        fetch(window.OQC_CONFIG.baseUrl + "/modules/inspection/api/submit_lot_result.php", {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.success) {
                Swal.fire('Gagal!', data.message || 'Terjadi kesalahan', 'error');
                return;
            }
            Swal.fire({
                icon: 'success',
                title: 'Status Direset',
                text: 'Lot kembali dalam status sedang diinspeksi.',
                timer: 1200,
                showConfirmButton: false
            });
            loadWorkbenchSessionData(currentSessionId);
        })
        .catch(function() {
            Swal.fire('Error!', 'Gagal menghubungi server', 'error');
        });
    });
}

function confirmRemoveLot(lotId, boxNum, lotNo, refNo, qty) {
    if (!lotId || !currentSessionId) return;

    var totalBoxesInWorkbench = (window.lastWorkbenchSessionData && window.lastWorkbenchSessionData.session_lots) ?
        window.lastWorkbenchSessionData.session_lots.length : 1;
    var isSingleBox = (totalBoxesInWorkbench <= 1);

    var noteHtml = isSingleBox
        ? '<div style="background-color: #fff1f2; border: 1.5px solid #fecdd3; border-radius: 8px; padding: 8px 10px; color: #9f1239; font-size: 11px; margin-top: 8px;">' +
          '<b>⚠️ PERHATIAN:</b> Box ini adalah satu-satunya box dalam sesi ini. Membatalkan box ini akan <b>otomatis membatalkan seluruh sesi pemeriksaan</b>, mengembalikan Safety Stock (jika ada), dan membebaskan label agar siap discan ulang.' +
          '</div>'
        : '<div style="background-color: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 8px; padding: 7px 10px; color: #065f46; font-size: 11px; display: flex; align-items: flex-start; gap: 6px;">' +
          '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="flex-shrink: 0; margin-top: 1px;"><polyline points="20 6 9 17 4 12"/></svg>' +
          '<span><b>Label Bebas:</b> Setelah box ini dihapus, barcode/label ini dapat discan kembali pada Kanban lain atau PC lain tanpa ditolak sistem.</span>' +
          '</div>';

    Swal.fire({
        title: isSingleBox ? 'Batal Pakai & Batalkan Sesi?' : ('Batal Pakai Box #' + boxNum + '?'),
        html: '<div style="text-align: left; font-size: 12px; color: #334155; line-height: 1.5;">' +
            '<p style="margin-bottom: 8px;">Apakah Anda yakin ingin membatalkan penggunaan dan menghapus box ini dari sesi pemeriksaan?</p>' +
            '<div style="background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 7px 10px; margin-bottom: 9px; font-size: 11.5px;">' +
                '<div><b>Nomor Box:</b> Box #' + boxNum + '</div>' +
                '<div><b>Lot Number:</b> <span style="font-family: monospace; font-weight: 700;">' + escapeHtml(lotNo) + '</span></div>' +
                (refNo ? '<div><b>Ref Number:</b> <span style="font-family: monospace; font-weight: 700; color: #1e40af;">' + escapeHtml(refNo) + '</span></div>' : '') +
                '<div><b>Qty Lot:</b> ' + Number(qty).toLocaleString() + ' pcs</div>' +
            '</div>' +
            noteHtml +
        '</div>',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#e11d48',
        cancelButtonColor: '#64748b',
        confirmButtonText: isSingleBox ? 'Ya, Batalkan Box & Sesi' : 'Ya, Batal Pakai Box Ini',
        cancelButtonText: 'Batal',
        focusCancel: true
    }).then(function(result) {
        if (!result.isConfirmed) return;

        Swal.fire({
            title: isSingleBox ? 'Membatalkan Sesi Pemeriksaan...' : 'Menghapus Box dari Sesi...',
            allowOutsideClick: false,
            didOpen: function() { Swal.showLoading(); }
        });

        fetch(window.OQC_CONFIG.baseUrl + "/modules/inspection/api/remove_session_lot.php", {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ session_lot_id: parseInt(lotId) })
        })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (!res.success) {
                Swal.fire({
                    icon: 'error',
                    title: 'Gagal Membatalkan Box',
                    text: res.message || 'Terjadi kesalahan sistem'
                });
                return;
            }

            // Jika sesi ikut terhapus (karena box terakhir)
            if (res.session_deleted) {
                Swal.fire({
                    icon: 'success',
                    title: 'Sesi Berhasil Dibatalkan',
                    text: res.message || 'Box terakhir telah dibatalkan dan sesi telah dibersihkan.',
                    confirmButtonColor: '#059669',
                    timer: 2500,
                    timerProgressBar: true
                }).then(function() {
                    window.location.href = res.redirect_url || window.OQC_CONFIG.baseUrl + "/modules/inspection/index.php";
                });
                return;
            }

            // Jika box yang sedang difokuskan dihapus, reset fokus box agar otomatis berpindah
            if (activeFocusLotId === parseInt(lotId)) {
                activeFocusLotId = null;
            }

            Swal.fire({
                icon: 'success',
                title: 'Box Berhasil Dibatalkan',
                text: res.message || 'Label telah dibebaskan dan siap digunakan kembali.',
                confirmButtonColor: '#059669',
                timer: 2000,
                timerProgressBar: true
            }).then(function() {
                loadWorkbenchSessionData(currentSessionId);
            });
        })
        .catch(function(err) {
            Swal.fire({
                icon: 'error',
                title: 'Koneksi Error',
                text: 'Gagal menghubungi server saat menghapus box.'
            });
        });
    });
}

function handleWorkbenchHeaderBack(e) {
    if (e && e.preventDefault) e.preventDefault();
    var defaultIndexUrl = window.OQC_CONFIG.baseUrl + "/modules/inspection/index.php";

    // Jika belum ada session aktif yang dimuat
    if (!currentSessionId || !window.lastWorkbenchSessionData || !window.lastWorkbenchSessionData.session) {
        window.location.href = defaultIndexUrl;
        return;
    }

    var sess = window.lastWorkbenchSessionData.session;
    var sessLots = window.lastWorkbenchSessionData.session_lots || [];
    var sessType = String(sess.inspection_type || 'kanban').toLowerCase();
    var sessStatus = String(sess.status || '').toLowerCase();

    // Jika sesi sudah berstatus final (passed / rejected), langsung keluar tanpa alert
    if (sessStatus === 'passed' || sessStatus === 'rejected') {
        window.location.href = defaultIndexUrl;
        return;
    }

    // Klasifikasikan box: yang sudah selesai diinspeksi vs yang masih menggantung (in_progress)
    var completedLots = [];
    var pendingLots = [];

    for (var i = 0; i < sessLots.length; i++) {
        var lot = sessLots[i];
        var lr = String(lot.lot_result || 'in_progress').toLowerCase();
        var ls = String(lot.lot_status || 'ok').toLowerCase();
        var ngCnt = parseInt(lot.ng_count || 0, 10);

        if (lr === 'passed' || lr === 'rejected' || lr === 'skipped' || ls === 'ng_found' || ls === 'ng_quarantine' || ls === 'replaced' || ls === 'reinspected' || ngCnt > 0) {
            completedLots.push(lot);
        } else {
            pendingLots.push(lot);
        }
    }

    // KONDISI 1: SEMUA BOX SUDAH SELESAI (Tidak ada yang menggantung)
    // Langsung keluar tanpa memunculkan alert
    if (pendingLots.length === 0) {
        window.location.href = defaultIndexUrl;
        return;
    }

    // Disable navigasi unload guard agar tidak muncul alert ganda dari browser
    window.isWorkbenchIntentionallyExiting = true;

    // KONDISI 2: BELUM ADA BOX YANG DIINSPEKSI SAMA SEKALI (0 Selesai)
    if (completedLots.length === 0) {
        var typeLabel = sessType === 'safety_stock' ? 'Safety Stock' : 'Kanban';
        Swal.fire({
            title: 'Batalkan Sesi Inspeksi?',
            html: '<div style="text-align: left; font-size: 12.5px; color: #334155; line-height: 1.6;">' +
                '<p style="margin-bottom: 10px;">Sesi <b>' + typeLabel + '</b> ini belum mulai diinspeksi sama sekali. Apakah Anda yakin ingin keluar dan membatalkan sesi ini?</p>' +
                '<div style="background-color: #fff1f2; border: 1.5px solid #fecdd3; border-radius: 8px; padding: 10px 12px; color: #9f1239; font-size: 11.5px;">' +
                    '<b>Informasi:</b> Sesi ini akan dihapus dari sistem, dan seluruh label QR/box yang discan akan <b>dibebaskan kembali</b> sehingga dapat di-scan ulang tanpa status ganda.' +
                '</div>' +
            '</div>',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#e11d48',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Ya, Batalkan & Keluar',
            cancelButtonText: 'Tetap di Sini',
            focusCancel: true,
            allowOutsideClick: false
        }).then(function(result) {
            if (result.isConfirmed) {
                Swal.fire({
                    title: 'Membatalkan Sesi...',
                    text: 'Menghapus sesi & membebaskan label...',
                    allowOutsideClick: false,
                    didOpen: function() { Swal.showLoading(); }
                });

                var fd = new FormData();
                fd.append('session_id', currentSessionId);

                fetch(window.OQC_CONFIG.baseUrl + "/modules/inspection/api/cleanup_uninspected_lots.php", {
                    method: 'POST',
                    body: fd
                })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    if (res.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Sesi Dibatalkan',
                            text: res.message || 'Sesi telah dibatalkan dan seluruh label dibebaskan.',
                            confirmButtonColor: '#059669',
                            timer: 1600,
                            timerProgressBar: true
                        }).then(function() {
                            window.location.href = defaultIndexUrl;
                        });
                    } else {
                        window.isWorkbenchIntentionallyExiting = false;
                        Swal.fire({
                            icon: 'error',
                            title: 'Gagal Membatalkan Sesi',
                            text: res.message || 'Terjadi kesalahan sistem'
                        });
                    }
                })
                .catch(function() {
                    window.isWorkbenchIntentionallyExiting = false;
                    Swal.fire({
                        icon: 'error',
                        title: 'Koneksi Error',
                        text: 'Gagal menghubungi server saat membatalkan sesi.'
                    });
                });
            } else {
                window.isWorkbenchIntentionallyExiting = false;
            }
        });
        return;
    }

    // KONDISI 3: SEBAGIAN BOX SUDAH DIINSPEKSI (Misal 2 Selesai, 1 Belum)
    Swal.fire({
        title: 'Keluar dari Sesi Inspeksi?',
        html: '<div style="text-align: left; font-size: 12.5px; color: #334155; line-height: 1.6;">' +
            '<p style="margin-bottom: 8px;">Anda telah menyelesaikan inspeksi <b style="color: #059669;">' + completedLots.length + ' box</b>, namun masih ada <b style="color: #d97706;">' + pendingLots.length + ' box</b> yang belum diinspeksi.</p>' +
            '<div style="background-color: #f8fafc; border: 1.5px solid #cbd5e1; border-radius: 8px; padding: 10px 12px; margin-bottom: 8px; font-size: 12px;">' +
                '<ul style="margin: 0; padding-left: 18px; line-height: 1.7;">' +
                    '<li><b style="color: #059669;">' + completedLots.length + ' Box selesai:</b> Tetap <b>tersimpan rapi</b> sebagai hasil inspeksi sah.</li>' +
                    '<li><b style="color: #e11d48;">' + pendingLots.length + ' Box belum selesai:</b> Dibatalkan & labelnya <b>dibebaskan kembali</b> agar dapat diinspeksi nanti.</li>' +
                '</ul>' +
            '</div>' +
            '<p style="font-size: 11.5px; color: #64748b; margin: 0;">Apakah Anda ingin menyimpan hasil yang ada dan keluar sekarang?</p>' +
        '</div>',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#2563eb',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Ya, Simpan Hasil & Keluar',
        cancelButtonText: 'Lanjutkan Inspeksi',
        focusCancel: false,
        allowOutsideClick: false
    }).then(function(result) {
        if (result.isConfirmed) {
            Swal.fire({
                title: 'Menyimpan Hasil & Membebaskan Box Sisa...',
                allowOutsideClick: false,
                didOpen: function() { Swal.showLoading(); }
            });

            var fd = new FormData();
            fd.append('session_id', currentSessionId);

            fetch(window.OQC_CONFIG.baseUrl + "/modules/inspection/api/cleanup_uninspected_lots.php", {
                method: 'POST',
                body: fd
            })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (res.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Hasil Tersimpan',
                        text: res.message || 'Hasil inspeksi berhasil disimpan.',
                        confirmButtonColor: '#059669',
                        timer: 1800,
                        timerProgressBar: true
                    }).then(function() {
                        window.location.href = defaultIndexUrl;
                    });
                } else {
                    window.isWorkbenchIntentionallyExiting = false;
                    Swal.fire({
                        icon: 'error',
                        title: 'Gagal Memproses',
                        text: res.message || 'Terjadi kesalahan sistem'
                    });
                }
            })
            .catch(function() {
                window.isWorkbenchIntentionallyExiting = false;
                Swal.fire({
                    icon: 'error',
                    title: 'Koneksi Error',
                    text: 'Gagal menghubungi server.'
                });
            });
        } else {
            window.isWorkbenchIntentionallyExiting = false;
        }
    });
}

// =========================================================================
// ADD SESSION LOT HANDLERS (Unified with Wizard Step 2)
// =========================================================================

function openAddLotModal() {
    openScanModalForAddBox();
}

function closeAddLotModal() {
    closeScanModal(true);
}



function openScanModalForAddBox() {
    window.isAddingBoxToActiveSession = true;

    // Reset list scan sementara
    clearAllScannedLabels();
    if (typeof resetLiveScanAlertPanel === 'function') resetLiveScanAlertPanel();
    selectedSsLotIds = [];
    selectedSsSessionIds = [];

    // Ambil context dari sesi yang sedang aktif
    var s = (window.lastWorkbenchSessionData && window.lastWorkbenchSessionData.session) ? window.lastWorkbenchSessionData.session : null;
    var partCode = s ? (s.session_part_code || s.part_code || '') : '';
    var partName = s ? (s.session_part_name || s.part_name || '') : '';
    var customer = s ? (s.customer || 'PT Epson') : 'PT Epson';
    var kanbanNo = s ? (s.kanban_no || '-') : '-';
    var docNo = s ? (s.doc_no || '-') : '-';
    var inspType = s ? (s.inspection_type || 'kanban') : 'kanban';
    var planQty = s ? (parseInt(s.plan_qty || s.kanban_qty || s.total_lot_qty || 0, 10)) : 0;
    var planId = s ? (parseInt(s.kanban_item_id || 0, 10)) : 0;

    selectedPlanningId = planId;
    selectedPlanningType = inspType;
    selectedPlanningPartCode = partCode;
    selectedPlanningQty = planQty;
    selectedPlanningTotalPassedQty = 0;
    selectedPlanningTargetTotalQty = planQty;
    selectedAvailableSsQty = 0;

    // Isi ringkasan planning di header Step 2
    var partCodeEl = document.getElementById('selected-plan-part-code');
    if (partCodeEl) partCodeEl.textContent = partCode;
    var descEl = document.getElementById('selected-plan-desc');
    if (descEl) descEl.textContent = partName;
    var custEl = document.getElementById('selected-plan-cust');
    if (custEl) custEl.textContent = customer;
    var kbNoEl = document.getElementById('selected-plan-kanban-no');
    if (kbNoEl) kbNoEl.textContent = kanbanNo;
    var scanPartInput = document.getElementById('scan-part-code');
    if (scanPartInput) scanPartInput.value = partCode;

    // Sembunyikan Step 1 dan tampilkan Step 2 langsung
    var step1El = document.getElementById('wizard-step-1-content');
    var step2El = document.getElementById('wizard-step-2-content');
    if (step1El) {
        step1El.classList.add('hidden');
        step1El.style.display = 'none';
    }
    if (step2El) {
        step2El.classList.remove('hidden');
        step2El.style.display = 'flex';
    }

    // Ubah header modal
    var titleEl = document.getElementById('wizard-modal-title');
    if (titleEl) titleEl.textContent = 'Tambah Box ke Sesi Aktif';
    var subEl = document.getElementById('wizard-modal-subtitle');
    if (subEl) subEl.textContent = 'Scan barcode QR label box baru untuk ditambahkan ke Sesi #' + currentSessionId + ' (' + partCode + ')';

    var stepTag = document.getElementById('wizard-step-tag');
    if (stepTag) {
        stepTag.textContent = 'Tambah Box Sesi #' + currentSessionId;
        stepTag.style.backgroundColor = '#eff6ff';
        stepTag.style.color = '#1d4ed8';
        stepTag.style.borderColor = '#bfdbfe';
    }

    // Sembunyikan tombol 'Ganti Item' agar inspector fokus pada box part sesi ini
    var btnChangeItem = document.getElementById('btn-change-item-header');
    if (btnChangeItem) btnChangeItem.style.display = 'none';

    // Render ulang tabel scan kosong
    renderScannedLabelsTable();

    // Buka modal overlay
    var overlay = document.getElementById('scan-modal-overlay');
    if (overlay) {
        overlay.classList.remove('hidden');
        overlay.style.display = 'flex';
    }

    // Autofocus ke input scan
    var rawInput = document.getElementById('scan-qr-raw');
    if (rawInput) {
        rawInput.value = '';
        setTimeout(function() { rawInput.focus(); }, 150);
    }
}

function submitAddBoxesToActiveSession() {
    if (!currentSessionId) {
        Swal.fire({
            icon: 'error',
            title: 'Sesi Tidak Ditemukan',
            text: 'ID Sesi aktif tidak valid.'
        });
        return;
    }

    if (!scannedLabelsList || scannedLabelsList.length === 0) {
        Swal.fire({
            icon: 'warning',
            title: 'Scan Label Diperlukan',
            text: 'Silakan scan setidaknya 1 label barcode QR box sebelum menyimpan!'
        });
        return;
    }

    setScanModalLoading(true, 'Mendaftarkan box baru ke sesi #' + currentSessionId + '...');
    hideScanModalError();

    var s = (window.lastWorkbenchSessionData && window.lastWorkbenchSessionData.session) ? window.lastWorkbenchSessionData.session : null;
    var targetPartCode = s ? (s.session_part_code || s.part_code || selectedPlanningPartCode || '') : (selectedPlanningPartCode || '');

    var lotsPayload = scannedLabelsList.map(function(l) {
        return {
            part_code: l.Z1 || targetPartCode,
            lot_number: l.Z2 || '',
            qty: parseInt(l.Z3, 10) || 0,
            ref_number: l.Z5 || '',
            raw_qr: l.raw || ('Z1' + (l.Z1 || targetPartCode) + '|Z2' + (l.Z2 || '') + '|Z3' + (l.Z3 || 0) + '|Z5' + (l.Z5 || '')),
            is_safety_stock: !!l.is_safety_stock_alloc,
            was_rejected_before: !!l.was_rejected_before,
            rejected_info: l.rejected_info || null
        };
    });

    var postData = {
        session_id: currentSessionId,
        part_code: targetPartCode,
        lots: lotsPayload
    };

    fetch(window.OQC_CONFIG.baseUrl + "/modules/inspection/api/add_session_lot.php", {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify(postData)
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        setScanModalLoading(false);
        if (!res.success) {
            showScanModalError(res.message || 'Gagal menambahkan box ke sesi.');
            return;
        }

        // Sukses! Tutup modal scan dan reset flag
        window.isAddingBoxToActiveSession = false;
        closeScanModal(true);

        var addedCount = (res.added_lots && Array.isArray(res.added_lots)) ? res.added_lots.length : scannedLabelsList.length;

        Swal.fire({
            icon: 'success',
            title: 'Box Berhasil Ditambahkan',
            text: res.message || (addedCount + ' box berhasil didaftarkan ke sesi.'),
            confirmButtonColor: '#059669',
            timer: 2000,
            timerProgressBar: true
        }).then(function() {
            loadWorkbenchSessionData(currentSessionId);
        });
    })
    .catch(function(err) {
        setScanModalLoading(false);
        console.error('Add session lot error:', err);
        showScanModalError('Gagal menghubungi server saat mendaftarkan box: ' + (err.message || err));
    });
}


function cancelReplacementLot(lotId, lotNumber) {
    if (!lotId) return;

    Swal.fire({
        title: 'Batalkan Box Pengganti?',
        html: 'Apakah Anda yakin ingin membatalkan box pengganti untuk Lot <b>#' + escapeHtml(lotNumber) + '</b>?<br><br><span style="font-size: 11.5px; color: #be123c; background-color: #fff1f2; padding: 6px 10px; border-radius: 6px; display: inline-block;">Box pengganti ini akan dihapus dari sesi, dan box sebelumnya akan dikembalikan ke status <b>DITOLAK (REJECTED)</b> dengan temuan NG semula.</span>',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#e11d48',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Ya, Batalkan Box Pengganti',
        cancelButtonText: 'Batal'
    }).then(function(result) {
        if (!result.isConfirmed) return;

        Swal.fire({
            title: 'Membatalkan Box Pengganti...',
            allowOutsideClick: false,
            didOpen: function() { Swal.showLoading(); }
        });

        fetch(window.OQC_CONFIG.baseUrl + "/modules/inspection/api/cancel_replacement_lot.php", {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ replacement_lot_id: parseInt(lotId), session_lot_id: parseInt(lotId) })
        })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (!res.success) {
                Swal.fire({
                    icon: 'error',
                    title: 'Gagal Membatalkan',
                    text: res.message || 'Terjadi kesalahan sistem'
                });
                return;
            }

            Swal.fire({
                icon: 'success',
                title: 'Box Pengganti Dibatalkan',
                text: res.message || 'Lot berhasil dikembalikan ke status REJECTED.',
                confirmButtonColor: '#059669'
            }).then(function() {
                loadWorkbenchSessionData(currentSessionId);
            });
        })
        .catch(function(err) {
            Swal.fire({
                icon: 'error',
                title: 'Koneksi Error',
                text: 'Gagal terhubung ke server!'
            });
        });
    });
}

function cancelSortLot(lotId, lotNumber) {
    if (!lotId) return;

    Swal.fire({
        title: 'Batalkan Re-Inspeksi / Sortir?',
        html: 'Apakah Anda yakin ingin membatalkan tindakan sortir pada Lot <b>#' + escapeHtml(lotNumber) + '</b>?<br><br><span style="font-size: 11.5px; color: #be123c; background-color: #fff1f2; padding: 6px 10px; border-radius: 6px; display: inline-block;">Status lot akan dikembalikan menjadi <b>DITOLAK (REJECTED)</b> dan riwayat sortir dibatalkan (temuan NG aktif kembali).</span>',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#e11d48',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Ya, Kembalikan ke REJECTED',
        cancelButtonText: 'Batal'
    }).then(function(result) {
        if (!result.isConfirmed) return;

        Swal.fire({
            title: 'Membatalkan Sortir...',
            allowOutsideClick: false,
            didOpen: function() { Swal.showLoading(); }
        });

        fetch(window.OQC_CONFIG.baseUrl + "/modules/inspection/api/cancel_sort_lot.php", {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ session_lot_id: parseInt(lotId) })
        })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (!res.success) {
                Swal.fire({
                    icon: 'error',
                    title: 'Gagal Membatalkan',
                    text: res.message || 'Terjadi kesalahan sistem'
                });
                return;
            }

            Swal.fire({
                icon: 'success',
                title: 'Sortir Dibatalkan',
                text: res.message || 'Lot berhasil dikembalikan ke status REJECTED.',
                confirmButtonColor: '#059669'
            }).then(function() {
                loadWorkbenchSessionData(currentSessionId);
            });
        })
        .catch(function(err) {
            Swal.fire({
                icon: 'error',
                title: 'Koneksi Error',
                text: 'Gagal terhubung ke server!'
            });
        });
    });
}



// ═══════════════════════════════════════════════════════════════════════════
// LOT RE-INSPECTION MODAL HANDLERS (OPSI A: PER-LOT AQL WORKBENCH)
// ═══════════════════════════════════════════════════════════════════════════
var currentLotReinspTab = 'replace';

