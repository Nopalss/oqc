    // ── EXCEL-STYLE COLUMN HEADER FILTERS ENGINE (PART OVERVIEW AUDIT TABLE) ───
    var PartOverviewExcelFilterState = {
        activeCol: null,
        filters: {
            date: new Set(),
            model: new Set(),
            defect: new Set(),
            type: new Set(),
            customer: new Set(),
            inspector: new Set()
        },
        options: {
            date: [],
            model: [],
            defect: [],
            type: [],
            customer: [],
            inspector: []
        }
    };

    function initPartOverviewExcelFilters() {
        var rows = document.querySelectorAll('tr.part-overview-history-row');
        if (!rows || rows.length === 0) return;

        var sets = {
            date: new Set(),
            model: new Set(),
            defect: new Set(),
            type: new Set(),
            customer: new Set(),
            inspector: new Set()
        };

        rows.forEach(function(r) {
            var d   = (r.getAttribute('data-filter-date') || '').trim();
            var m   = (r.getAttribute('data-filter-model') || '').trim();
            var def = (r.getAttribute('data-filter-defect') || '').trim();
            var t   = (r.getAttribute('data-filter-type') || '').trim();
            var c   = (r.getAttribute('data-filter-customer') || '').trim();
            var ins = (r.getAttribute('data-filter-inspector') || '').trim();

            if (d) sets.date.add(d);
            if (m) sets.model.add(m);
            if (def) sets.defect.add(def);
            if (t) sets.type.add(t);
            if (c) sets.customer.add(c);
            if (ins) sets.inspector.add(ins);
        });

        ['date', 'model', 'defect', 'type', 'customer', 'inspector'].forEach(function(col) {
            PartOverviewExcelFilterState.options[col] = Array.from(sets[col]).sort();
            renderPartOverviewFilterCheckboxes(col);
        });
    }

    function renderPartOverviewFilterCheckboxes(col) {
        var container = document.getElementById('part-overview-filter-list-' + col);
        if (!container) return;

        var options = PartOverviewExcelFilterState.options[col] || [];
        var activeSet = PartOverviewExcelFilterState.filters[col];
        var isAllActive = (activeSet.size === 0);

        container.innerHTML = '';
        if (options.length === 0) {
            container.innerHTML = '<div style="color: #94a3b8; padding: 6px; text-align: center; font-size: 10px;">Tidak ada opsi</div>';
            return;
        }

        options.forEach(function(opt) {
            var isChecked = isAllActive || activeSet.has(opt);
            var itemDiv = document.createElement('label');
            itemDiv.className = 'excel-filter-item';
            itemDiv.setAttribute('data-val', opt.toLowerCase());

            var cb = document.createElement('input');
            cb.type = 'checkbox';
            cb.value = opt;
            cb.checked = isChecked;
            cb.onchange = function() {
                updatePartOverviewFilterCountInfo(col);
            };

            var span = document.createElement('span');
            span.textContent = opt;
            span.style.overflow = 'hidden';
            span.style.textOverflow = 'ellipsis';
            span.style.whiteSpace = 'nowrap';
            span.title = opt;

            itemDiv.appendChild(cb);
            itemDiv.appendChild(span);
            container.appendChild(itemDiv);
        });

        updatePartOverviewFilterCountInfo(col);
    }

    function updatePartOverviewFilterCountInfo(col) {
        var dropdown = document.getElementById('part-overview-filter-dropdown-' + col);
        if (!dropdown) return;
        var info = dropdown.querySelector('.filter-count-info');
        if (!info) return;

        var checkboxes = dropdown.querySelectorAll('.excel-filter-list input[type="checkbox"]');
        var checkedCount = 0;
        checkboxes.forEach(function(cb) {
            if (cb.checked) checkedCount++;
        });
        info.textContent = checkedCount + ' / ' + checkboxes.length + ' dipilih';
    }

    function togglePartOverviewExcelFilter(event, col) {
        event.stopPropagation();
        var btn = event.currentTarget;
        var dropdown = document.getElementById('part-overview-filter-dropdown-' + col);
        if (!dropdown || !btn) return;

        var isVisible = (dropdown.style.display === 'block');
        closeAllPartOverviewExcelFilters();

        if (!isVisible) {
            dropdown.style.display = 'block';
            PartOverviewExcelFilterState.activeCol = col;

            // Hitung posisi fixed secara dinamis dari tombol
            var rect = btn.getBoundingClientRect();
            var ddWidth = dropdown.offsetWidth || 245;
            var ddHeight = dropdown.offsetHeight || 260;
            var winWidth = window.innerWidth;
            var winHeight = window.innerHeight;

            var top = rect.bottom + 6;
            if (top + ddHeight > winHeight - 10 && rect.top - ddHeight - 6 > 10) {
                top = rect.top - ddHeight - 6;
            }

            var left = rect.left;
            if (dropdown.classList.contains('align-right') || (left + ddWidth > winWidth - 15)) {
                left = rect.right - ddWidth;
            }
            if (left < 10) left = 10;

            dropdown.style.top = top + 'px';
            dropdown.style.left = left + 'px';

            var searchInput = dropdown.querySelector('.excel-filter-search');
            if (searchInput) {
                searchInput.value = '';
                onSearchPartOverviewFilterOptions(col, '');
                setTimeout(function() { searchInput.focus(); }, 50);
            }
        }
    }

    function closeAllPartOverviewExcelFilters() {
        document.querySelectorAll('#viewContainerPartOverviewMode .excel-filter-dropdown').forEach(function(dd) {
            dd.style.display = 'none';
        });
        PartOverviewExcelFilterState.activeCol = null;
    }

    function onSearchPartOverviewFilterOptions(col, keyword) {
        var container = document.getElementById('part-overview-filter-list-' + col);
        if (!container) return;
        var kw = (keyword || '').toLowerCase().trim();
        var items = container.querySelectorAll('.excel-filter-item');
        items.forEach(function(item) {
            var val = item.getAttribute('data-val') || '';
            if (!kw || val.indexOf(kw) !== -1) {
                item.style.display = 'flex';
            } else {
                item.style.display = 'none';
            }
        });
    }

    function toggleSelectAllPartOverviewFilter(col, selectAll) {
        var container = document.getElementById('part-overview-filter-list-' + col);
        if (!container) return;
        var checkboxes = container.querySelectorAll('input[type="checkbox"]');
        checkboxes.forEach(function(cb) {
            if (cb.closest('.excel-filter-item').style.display !== 'none') {
                cb.checked = selectAll;
            }
        });
        updatePartOverviewFilterCountInfo(col);
    }

    function applyPartOverviewExcelFilters() {
        var cols = ['date', 'model', 'defect', 'type', 'customer', 'inspector'];

        cols.forEach(function(col) {
            var container = document.getElementById('part-overview-filter-list-' + col);
            if (!container) return;

            var checkboxes = container.querySelectorAll('input[type="checkbox"]');
            var allCount = checkboxes.length;
            var checkedValues = [];

            checkboxes.forEach(function(cb) {
                if (cb.checked) {
                    checkedValues.push(cb.value);
                }
            });

            var btn = document.querySelector('#viewContainerPartOverviewMode .th-filter-btn[data-col="' + col + '"]');

            if (checkedValues.length === 0 || checkedValues.length === allCount) {
                PartOverviewExcelFilterState.filters[col].clear();
                if (btn) btn.classList.remove('active');
            } else {
                PartOverviewExcelFilterState.filters[col] = new Set(checkedValues);
                if (btn) btn.classList.add('active');
            }
        });

        closeAllPartOverviewExcelFilters();
        executePartOverviewFiltering();
    }

    function resetSinglePartOverviewColFilter(col) {
        PartOverviewExcelFilterState.filters[col].clear();
        var btn = document.querySelector('#viewContainerPartOverviewMode .th-filter-btn[data-col="' + col + '"]');
        if (btn) btn.classList.remove('active');
        renderPartOverviewFilterCheckboxes(col);
        closeAllPartOverviewExcelFilters();
        executePartOverviewFiltering();
    }

    function resetAllPartOverviewExcelFilters() {
        ['date', 'model', 'defect', 'type', 'customer', 'inspector'].forEach(function(col) {
            PartOverviewExcelFilterState.filters[col].clear();
            var btn = document.querySelector('#viewContainerPartOverviewMode .th-filter-btn[data-col="' + col + '"]');
            if (btn) btn.classList.remove('active');
            renderPartOverviewFilterCheckboxes(col);
        });
        var searchInput = document.getElementById('partOverviewTableSearchInput');
        if (searchInput) searchInput.value = '';
        closeAllPartOverviewExcelFilters();
        executePartOverviewFiltering();
    }

    function executePartOverviewFiltering() {
        var rows = document.querySelectorAll('tr.part-overview-history-row');
        if (!rows || rows.length === 0) return;

        var searchVal = (document.getElementById('partOverviewTableSearchInput') ? document.getElementById('partOverviewTableSearchInput').value : '').toLowerCase().trim();

        var activeFilterCols = [];
        ['date', 'model', 'defect', 'type', 'customer', 'inspector'].forEach(function(c) {
            if (PartOverviewExcelFilterState.filters[c].size > 0) activeFilterCols.push(c);
        });

        var visibleCount = 0;
        var totalRows = rows.length;

        rows.forEach(function(r) {
            if (searchVal) {
                var rowText = r.textContent.toLowerCase();
                if (rowText.indexOf(searchVal) === -1) {
                    r.style.display = 'none';
                    return;
                }
            }

            var d   = (r.getAttribute('data-filter-date') || '').trim();
            var m   = (r.getAttribute('data-filter-model') || '').trim();
            var def = (r.getAttribute('data-filter-defect') || '').trim();
            var t   = (r.getAttribute('data-filter-type') || '').trim();
            var c   = (r.getAttribute('data-filter-customer') || '').trim();
            var ins = (r.getAttribute('data-filter-inspector') || '').trim();

            var match = true;
            if (PartOverviewExcelFilterState.filters.date.size > 0 && !PartOverviewExcelFilterState.filters.date.has(d)) match = false;
            if (match && PartOverviewExcelFilterState.filters.model.size > 0 && !PartOverviewExcelFilterState.filters.model.has(m)) match = false;
            if (match && PartOverviewExcelFilterState.filters.defect.size > 0 && !PartOverviewExcelFilterState.filters.defect.has(def)) match = false;
            if (match && PartOverviewExcelFilterState.filters.type.size > 0 && !PartOverviewExcelFilterState.filters.type.has(t)) match = false;
            if (match && PartOverviewExcelFilterState.filters.customer.size > 0 && !PartOverviewExcelFilterState.filters.customer.has(c)) match = false;
            if (match && PartOverviewExcelFilterState.filters.inspector.size > 0 && !PartOverviewExcelFilterState.filters.inspector.has(ins)) match = false;

            if (match) {
                r.style.display = '';
                visibleCount++;
                var idxTd = r.querySelector('.row-index');
                if (idxTd) idxTd.textContent = visibleCount;
            } else {
                r.style.display = 'none';
            }
        });

        // Update active filter bar
        var indicatorBar = document.getElementById('part-overview-excel-filter-active-bar');
        var summaryText  = document.getElementById('part-overview-excel-filter-summary-text');
        var badgeCount   = document.getElementById('partOverviewTableCountBadge');

        var isFiltered = (activeFilterCols.length > 0 || searchVal !== '');

        if (isFiltered) {
            if (indicatorBar) indicatorBar.style.display = 'flex';
            if (summaryText) {
                var filterNames = activeFilterCols.slice();
                if (searchVal) filterNames.push('pencarian "' + searchVal + '"');
                summaryText.innerText = 'Menyaring ' + visibleCount + ' dari ' + totalRows + ' temuan (' + filterNames.join(', ') + ')';
            }
            if (badgeCount) {
                badgeCount.innerText = visibleCount + ' / ' + totalRows + ' Temuan';
            }
        } else {
            if (indicatorBar) indicatorBar.style.display = 'none';
            if (badgeCount) {
                badgeCount.innerText = totalRows + ' Temuan';
            }
        }
    }



    // ══════════════════════════════════════════════════════════════════════════
    var OverviewExcelFilterState = {
        activeCol: null,
        filters: {
            date: new Set(),
            part: new Set(),
            model: new Set(),
            type: new Set(),
            customer: new Set(),
            inspector: new Set()
        },
        options: {
            date: [],
            part: [],
            model: [],
            type: [],
            customer: [],
            inspector: []
        }
    };

    function initOverviewExcelFilters() {
        var rows = document.querySelectorAll('tr.overview-history-row');
        if (!rows || rows.length === 0) return;

        var sets = {
            date: new Set(),
            part: new Set(),
            model: new Set(),
            type: new Set(),
            customer: new Set(),
            inspector: new Set()
        };

        rows.forEach(function(r) {
            var d   = (r.getAttribute('data-filter-date') || '').trim();
            var p   = (r.getAttribute('data-filter-part') || '').trim();
            var m   = (r.getAttribute('data-filter-model') || '').trim();
            var t   = (r.getAttribute('data-filter-type') || '').trim();
            var c   = (r.getAttribute('data-filter-customer') || '').trim();
            var ins = (r.getAttribute('data-filter-inspector') || '').trim();

            if (d) sets.date.add(d);
            if (p) sets.part.add(p);
            if (m) sets.model.add(m);
            if (t) sets.type.add(t);
            if (c) sets.customer.add(c);
            if (ins) sets.inspector.add(ins);
        });

        ['date', 'part', 'model', 'type', 'customer', 'inspector'].forEach(function(col) {
            OverviewExcelFilterState.options[col] = Array.from(sets[col]).sort();
            renderOverviewFilterCheckboxes(col);
        });
    }

    function renderOverviewFilterCheckboxes(col) {
        var container = document.getElementById('overview-filter-list-' + col);
        if (!container) return;

        var options = OverviewExcelFilterState.options[col] || [];
        var activeSet = OverviewExcelFilterState.filters[col];
        var isAllActive = (activeSet.size === 0);

        container.innerHTML = '';
        if (options.length === 0) {
            container.innerHTML = '<div style="color: #94a3b8; padding: 6px; text-align: center; font-size: 10px;">Tidak ada opsi</div>';
            return;
        }

        options.forEach(function(opt) {
            var isChecked = isAllActive || activeSet.has(opt);
            var itemDiv = document.createElement('label');
            itemDiv.className = 'excel-filter-item';
            itemDiv.setAttribute('data-val', opt.toLowerCase());

            var cb = document.createElement('input');
            cb.type = 'checkbox';
            cb.value = opt;
            cb.checked = isChecked;
            cb.onchange = function() {
                updateOverviewFilterCountInfo(col);
            };

            var span = document.createElement('span');
            span.textContent = opt;
            span.style.overflow = 'hidden';
            span.style.textOverflow = 'ellipsis';
            span.style.whiteSpace = 'nowrap';
            span.title = opt;

            itemDiv.appendChild(cb);
            itemDiv.appendChild(span);
            container.appendChild(itemDiv);
        });

        updateOverviewFilterCountInfo(col);
    }

    function updateOverviewFilterCountInfo(col) {
        var dropdown = document.getElementById('overview-filter-dropdown-' + col);
        if (!dropdown) return;
        var info = dropdown.querySelector('.filter-count-info');
        if (!info) return;

        var checkboxes = dropdown.querySelectorAll('.excel-filter-list input[type="checkbox"]');
        var checkedCount = 0;
        checkboxes.forEach(function(cb) {
            if (cb.checked) checkedCount++;
        });
        info.textContent = checkedCount + ' / ' + checkboxes.length + ' dipilih';
    }

    function toggleOverviewExcelFilter(event, col) {
        event.stopPropagation();
        var btn = event.currentTarget;
        var dropdown = document.getElementById('overview-filter-dropdown-' + col);
        if (!dropdown || !btn) return;

        var isVisible = (dropdown.style.display === 'block');
        closeAllOverviewExcelFilters();

        if (!isVisible) {
            dropdown.style.display = 'block';
            OverviewExcelFilterState.activeCol = col;

            // Hitung posisi fixed secara dinamis dari tombol
            var rect = btn.getBoundingClientRect();
            var ddWidth = dropdown.offsetWidth || 245;
            var ddHeight = dropdown.offsetHeight || 260;
            var winWidth = window.innerWidth;
            var winHeight = window.innerHeight;

            var top = rect.bottom + 6;
            // Jika melebihi batas bawah viewport, buka ke atas
            if (top + ddHeight > winHeight - 10 && rect.top - ddHeight - 6 > 10) {
                top = rect.top - ddHeight - 6;
            }

            var left = rect.left;
            // Jika kolom di kanan atau dropdown overflow ke kanan layar, align kanan
            if (dropdown.classList.contains('align-right') || (left + ddWidth > winWidth - 15)) {
                left = rect.right - ddWidth;
            }
            if (left < 10) left = 10;

            dropdown.style.top = top + 'px';
            dropdown.style.left = left + 'px';

            var searchInput = dropdown.querySelector('.excel-filter-search');
            if (searchInput) {
                searchInput.value = '';
                onSearchOverviewFilterOptions(col, '');
                setTimeout(function() { searchInput.focus(); }, 50);
            }
        }
    }

    function closeAllOverviewExcelFilters() {
        document.querySelectorAll('#viewContainerOverviewMode .excel-filter-dropdown').forEach(function(dd) {
            dd.style.display = 'none';
        });
        OverviewExcelFilterState.activeCol = null;
    }

    function onSearchOverviewFilterOptions(col, keyword) {
        var container = document.getElementById('overview-filter-list-' + col);
        if (!container) return;
        var kw = (keyword || '').toLowerCase().trim();
        var items = container.querySelectorAll('.excel-filter-item');
        items.forEach(function(item) {
            var val = item.getAttribute('data-val') || '';
            if (!kw || val.indexOf(kw) !== -1) {
                item.style.display = 'flex';
            } else {
                item.style.display = 'none';
            }
        });
    }

    function toggleSelectAllOverviewFilter(col, selectAll) {
        var container = document.getElementById('overview-filter-list-' + col);
        if (!container) return;
        var checkboxes = container.querySelectorAll('input[type="checkbox"]');
        checkboxes.forEach(function(cb) {
            if (cb.closest('.excel-filter-item').style.display !== 'none') {
                cb.checked = selectAll;
            }
        });
        updateOverviewFilterCountInfo(col);
    }

    function applyOverviewExcelFilters() {
        var cols = ['date', 'part', 'model', 'type', 'customer', 'inspector'];

        cols.forEach(function(col) {
            var container = document.getElementById('overview-filter-list-' + col);
            if (!container) return;

            var checkboxes = container.querySelectorAll('input[type="checkbox"]');
            var allCount = checkboxes.length;
            var checkedValues = [];

            checkboxes.forEach(function(cb) {
                if (cb.checked) {
                    checkedValues.push(cb.value);
                }
            });

            var btn = document.querySelector('#viewContainerOverviewMode .th-filter-btn[data-col="' + col + '"]');

            if (checkedValues.length === 0 || checkedValues.length === allCount) {
                OverviewExcelFilterState.filters[col].clear();
                if (btn) btn.classList.remove('active');
            } else {
                OverviewExcelFilterState.filters[col] = new Set(checkedValues);
                if (btn) btn.classList.add('active');
            }
        });

        closeAllOverviewExcelFilters();
        executeOverviewFiltering();
    }

    function resetSingleOverviewColFilter(col) {
        OverviewExcelFilterState.filters[col].clear();
        var btn = document.querySelector('#viewContainerOverviewMode .th-filter-btn[data-col="' + col + '"]');
        if (btn) btn.classList.remove('active');
        renderOverviewFilterCheckboxes(col);
        closeAllOverviewExcelFilters();
        executeOverviewFiltering();
    }

    function resetAllOverviewExcelFilters() {
        ['date', 'part', 'model', 'type', 'customer', 'inspector'].forEach(function(col) {
            OverviewExcelFilterState.filters[col].clear();
            var btn = document.querySelector('#viewContainerOverviewMode .th-filter-btn[data-col="' + col + '"]');
            if (btn) btn.classList.remove('active');
            renderOverviewFilterCheckboxes(col);
        });
        var searchInput = document.getElementById('overviewTableSearchInput');
        if (searchInput) searchInput.value = '';
        closeAllOverviewExcelFilters();
        executeOverviewFiltering();
    }

    function executeOverviewFiltering() {
        var rows = document.querySelectorAll('tr.overview-history-row');
        if (!rows || rows.length === 0) return;

        var searchVal = (document.getElementById('overviewTableSearchInput') ? document.getElementById('overviewTableSearchInput').value : '').toLowerCase().trim();

        var activeFilterCols = [];
        ['date', 'part', 'model', 'type', 'customer', 'inspector'].forEach(function(c) {
            if (OverviewExcelFilterState.filters[c].size > 0) activeFilterCols.push(c);
        });

        var visibleCount = 0;
        var totalRows = rows.length;

        rows.forEach(function(r) {
            // 1. Text Search Filter
            if (searchVal) {
                var rowText = r.textContent.toLowerCase();
                if (rowText.indexOf(searchVal) === -1) {
                    r.style.display = 'none';
                    return;
                }
            }

            // 2. Excel Column Filters
            var d   = (r.getAttribute('data-filter-date') || '').trim();
            var p   = (r.getAttribute('data-filter-part') || '').trim();
            var m   = (r.getAttribute('data-filter-model') || '').trim();
            var t   = (r.getAttribute('data-filter-type') || '').trim();
            var c   = (r.getAttribute('data-filter-customer') || '').trim();
            var ins = (r.getAttribute('data-filter-inspector') || '').trim();

            var match = true;
            if (OverviewExcelFilterState.filters.date.size > 0 && !OverviewExcelFilterState.filters.date.has(d)) match = false;
            if (match && OverviewExcelFilterState.filters.part.size > 0 && !OverviewExcelFilterState.filters.part.has(p)) match = false;
            if (match && OverviewExcelFilterState.filters.model.size > 0 && !OverviewExcelFilterState.filters.model.has(m)) match = false;
            if (match && OverviewExcelFilterState.filters.type.size > 0 && !OverviewExcelFilterState.filters.type.has(t)) match = false;
            if (match && OverviewExcelFilterState.filters.customer.size > 0 && !OverviewExcelFilterState.filters.customer.has(c)) match = false;
            if (match && OverviewExcelFilterState.filters.inspector.size > 0 && !OverviewExcelFilterState.filters.inspector.has(ins)) match = false;

            if (match) {
                r.style.display = '';
                visibleCount++;
                var idxTd = r.querySelector('.row-index');
                if (idxTd) idxTd.textContent = visibleCount;
            } else {
                r.style.display = 'none';
            }
        });

        // Update active filter bar
        var indicatorBar = document.getElementById('overview-excel-filter-active-bar');
        var summaryText  = document.getElementById('overview-excel-filter-summary-text');
        var badgeCount   = document.getElementById('overviewTableCountBadge');

        var isFiltered = (activeFilterCols.length > 0 || searchVal !== '');

        if (isFiltered) {
            if (indicatorBar) indicatorBar.style.display = 'flex';
            if (summaryText) {
                var filterNames = activeFilterCols.slice();
                if (searchVal) filterNames.push('pencarian "' + searchVal + '"');
                summaryText.innerText = 'Menyaring ' + visibleCount + ' dari ' + totalRows + ' temuan (' + filterNames.join(', ') + ')';
            }
            if (badgeCount) {
                badgeCount.innerText = visibleCount + ' / ' + totalRows + ' Temuan';
            }
        } else {
            if (indicatorBar) indicatorBar.style.display = 'none';
            if (badgeCount) {
                badgeCount.innerText = totalRows + ' Temuan';
            }
        }
    }

    // Event listener: Klik di luar untuk menutup dropdown filter overview & defect dropdown
    document.addEventListener('click', function(e) {
        // Abaikan jika klik terjadi di dalam dropdown excel manapun
        if (e.target.closest('.excel-filter-dropdown')) {
            return;
        }

        if (!e.target.closest('#viewContainerOverviewMode .th-filter-btn')) {
            closeAllOverviewExcelFilters();
        }
        if (!e.target.closest('#overviewDefectDropdownContainer')) {
            closeOverviewDefectMenu();
        }
        if (!e.target.closest('#viewContainerPartOverviewMode .th-filter-btn')) {
            closeAllPartOverviewExcelFilters();
        }
        if (!e.target.closest('#partOverviewPartDropdownContainer')) {
            closePartOverviewMenu();
        }
    });
