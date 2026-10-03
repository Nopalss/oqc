/**
 * Core Application JavaScript Utilities (jQuery Powered)
 * Handles Sidebar Toggle, State Persistence, and SweetAlert2 Confirmations
 */

$(document).ready(function () {
    console.log("OQC System JavaScript Initialized Successfully.");

    // Helper to check if current viewport matches mobile breakpoint (< 768px)
    function isMobile() {
        return window.matchMedia('(max-width: 767.98px)').matches;
    }

    // Function to handle initial responsive sidebar state
    function initSidebar() {
        if (!isMobile()) {
            $("body").removeClass("sidebar-mobile-open");
            var isClosed = localStorage.getItem('oqc_sidebar_closed') === 'true';
            if (isClosed) {
                $("body").addClass("sidebar-closed");
            } else {
                $("body").removeClass("sidebar-closed");
            }
        } else {
            $("body").removeClass("sidebar-closed sidebar-mobile-open");
        }
    }

    initSidebar();

    // Re-evaluate on window resize
    $(window).on("resize", function () {
        if (!isMobile()) {
            $("body").removeClass("sidebar-mobile-open");
        } else {
            $("body").removeClass("sidebar-closed");
        }
    });

    // Toggle Sidebar Handler (Navbar Hamburger)
    $(document).on("click", "#navbar-sidebar-toggle", function (e) {
        e.preventDefault();
        if (isMobile()) {
            $("body").removeClass("sidebar-closed").toggleClass("sidebar-mobile-open");
        } else {
            $("body").removeClass("sidebar-mobile-open").toggleClass("sidebar-closed");
            var nowClosed = $("body").hasClass("sidebar-closed");
            localStorage.setItem('oqc_sidebar_closed', nowClosed);
        }
    });

    // Desktop Collapse Button Handler
    $(document).on("click", "#sidebar-collapse-btn", function (e) {
        e.preventDefault();
        $("body").removeClass("sidebar-mobile-open").toggleClass("sidebar-closed");
        var nowClosed = $("body").hasClass("sidebar-closed");
        localStorage.setItem('oqc_sidebar_closed', nowClosed);
    });

    // Mobile Close Button & Overlay Click Handler
    $(document).on("click", "#sidebar-overlay, #sidebar-close-mobile", function (e) {
        e.preventDefault();
        $("body").removeClass("sidebar-mobile-open");
    });

    // Close Mobile Sidebar on Nav Link Click
    $(document).on("click", "#sidebar a", function () {
        if (isMobile()) {
            $("body").removeClass("sidebar-mobile-open");
        }
    });

    // Auto dismiss flash alert after 5 seconds
    setTimeout(function () {
        $("#flash-alert").fadeOut(400, function () {
            $(this).remove();
        });
    }, 5000);
});

/**
 * Show SweetAlert2 Confirmation Dialog for Deletion Actions
 * 
 * @param {string} deleteUrl Target URL to execute deletion
 * @param {string} name Item name/title for display
 */
function confirmDelete(deleteUrl, name) {
    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: 'Konfirmasi Hapus Data',
            html: `Apakah Anda yakin ingin menghapus data <b>"${name}"</b>?<br><span style="font-size: 11px; color: #64748b;">Tindakan ini tidak dapat dibatalkan.</span>`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#e11d48',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Ya, Hapus Sekarang!',
            cancelButtonText: 'Batal'
        }).then(function(result) {
            if (result.isConfirmed) {
                window.location.href = deleteUrl;
            }
        });
    } else {
        if (confirm(`Apakah Anda yakin ingin menghapus "${name}"?`)) {
            window.location.href = deleteUrl;
        }
    }
}

/**
 * Device Workstation Line Management (LocalStorage + SweetAlert2)
 */
function getAppBaseUrl() {
    return window.APP_BASE_URL || '/';
}

function initDeviceLine() {
    var $btnNav = $('#btn-navbar-device-line');
    var $headerLine = $('#header-device-line-name');
    var isInspectorContext = ($btnNav.length > 0) || ($headerLine.length > 0);

    var savedLineName = localStorage.getItem('oqc_device_line_name') || localStorage.getItem('oqc_device_line');
    var savedLineId = localStorage.getItem('oqc_device_line_id');

    if (savedLineName && savedLineName.trim() !== '') {
        var cleanLine = savedLineName.trim();
        $('#navbar-device-line-name').text(cleanLine);
        $('#header-device-line-name').text(cleanLine);
    } else if (isInspectorContext) {
        // Inspector context but no device line set yet -> Prompt immediately
        setTimeout(function () {
            promptDeviceLine(false);
        }, 300);
    }
}

$(document).ready(function () {
    initDeviceLine();
});

/**
 * Open SweetAlert2 modal to select or enter workstation Line
 * @param {boolean} canCancel Allow closing without saving
 */
function promptDeviceLine(canCancel) {
    if (typeof Swal === 'undefined') {
        console.warn("SweetAlert2 is not loaded.");
        return;
    }

    var currentLineId = localStorage.getItem('oqc_device_line_id') || '';
    var currentLineName = localStorage.getItem('oqc_device_line_name') || localStorage.getItem('oqc_device_line') || '';
    var fallbackLines = [
       
    ];

    var apiUrl = getAppBaseUrl() + 'modules/inspection/api_lines.php';

    // Fetch data asynchronously without showing an annoying blocking loading popup
    fetch(apiUrl)
        .then(function (res) { return res.json(); })
        .then(function (data) {
            var lines = (data && data.success && Array.isArray(data.lines) && data.lines.length > 0) ? data.lines : fallbackLines;
            renderLinePromptModal(lines, currentLineId, currentLineName, canCancel);
        })
        .catch(function (err) {
            console.warn("Menggunakan daftar line fallback:", err);
            renderLinePromptModal(fallbackLines, currentLineId, currentLineName, canCancel);
        });
}

window.handleSwalLineChange = function (el) {
    var wrap = document.getElementById('swal-custom-line-wrapper');
    var input = document.getElementById('swal-line-input-custom');
    var errEl = document.getElementById('swal-line-error-msg');
    if (errEl) errEl.style.display = 'none';

    if (wrap) {
        if (el && el.value === '__custom__') {
            wrap.style.display = 'block';
            if (input) setTimeout(function () { input.focus(); }, 50);
        } else {
            wrap.style.display = 'none';
        }
    }
};

window.saveDeviceLineModal = function (btn) {
    var selectEl = document.getElementById('swal-line-select');
    var selectVal = selectEl ? selectEl.value : '';
    var errEl = document.getElementById('swal-line-error-msg');
    var finalLineId = 0;
    var finalLineName = '';

    if (!selectVal) {
        if (errEl) {
            errEl.textContent = 'Silakan pilih salah satu Line kerja!';
            errEl.style.display = 'block';
        } else {
            alert('Silakan pilih salah satu Line kerja!');
        }
        return;
    }

    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span>Menyimpan...</span>';
    }

    if (selectVal === '__custom__') {
        var customInput = document.getElementById('swal-line-input-custom');
        finalLineName = (customInput ? customInput.value : '').trim();
        if (!finalLineName) {
            if (errEl) {
                errEl.textContent = 'Nama Line baru/manual tidak boleh kosong!';
                errEl.style.display = 'block';
            } else {
                alert('Nama Line baru/manual tidak boleh kosong!');
            }
            if (customInput) customInput.focus();
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = 'Simpan Line Perangkat';
            }
            return;
        }

        // Simpan line baru ke database via API untuk mendapatkan line_id tabel
        var apiUrl = getAppBaseUrl() + 'modules/inspection/api_lines.php';
        var formData = new FormData();
        formData.append('name', finalLineName);

        fetch(apiUrl, {
            method: 'POST',
            body: formData
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            if (data && data.success && data.line_id) {
                finalLineId = parseInt(data.line_id);
                finalLineName = data.line_name || finalLineName;
            }
            applyDeviceLineStorageAndReload(finalLineId, finalLineName);
        })
        .catch(function (err) {
            console.error("Gagal mendaftarkan line baru:", err);
            // Fallback: simpan nama line walau fetch gagal
            applyDeviceLineStorageAndReload(0, finalLineName);
        });
        return;
    } else {
        var selectedOpt = selectEl.options[selectEl.selectedIndex];
        finalLineId = parseInt(selectVal) || 0;
        finalLineName = selectedOpt ? (selectedOpt.getAttribute('data-name') || selectedOpt.text) : '';
        applyDeviceLineStorageAndReload(finalLineId, finalLineName);
    }
};

function applyDeviceLineStorageAndReload(lineId, lineName) {
    if (lineId > 0) {
        localStorage.setItem('oqc_device_line_id', String(lineId));
    }
    if (lineName) {
        localStorage.setItem('oqc_device_line_name', lineName);
        localStorage.setItem('oqc_device_line', lineName); // backward compatibility
    }

    var elNav = document.getElementById('navbar-device-line-name');
    if (elNav) elNav.textContent = lineName;
    var elH = document.getElementById('header-device-line-name');
    if (elH) elH.textContent = lineName;

    if (typeof Swal !== 'undefined' && Swal.close) {
        Swal.close();
    }
    window.location.reload();
}

function renderLinePromptModal(lines, currentLineId, currentLineName, canCancel) {
    var optionsHtml = '';
    var matchedExisting = false;

    lines.forEach(function (l) {
        var isSelected = false;
        if (currentLineId && String(currentLineId) === String(l.id)) {
            isSelected = true;
        } else if (!currentLineId && currentLineName && currentLineName.toLowerCase() === l.name.toLowerCase()) {
            isSelected = true;
        }

        if (isSelected) matchedExisting = true;
        optionsHtml += `<option value="${l.id}" data-name="${l.name}" ${isSelected ? 'selected' : ''}>${l.name} ${l.description ? '(' + l.description + ')' : ''}</option>`;
    });

    var isCustomSelected = (!matchedExisting && currentLineName);
    optionsHtml += `<option value="__custom__" ${isCustomSelected ? 'selected' : ''}>+ Ketik Nama Line Baru (Manual)...</option>`;

    var cancelBtnHtml = canCancel ? 
        `<button type="button" onclick="Swal.close()" style="padding: 9px 18px; background: #ffffff; color: #475569; border: 1.5px solid #cbd5e1; border-radius: 8px; font-size: 13px; font-weight: 700; cursor: pointer; transition: all 0.15s;">Batal</button>` : '';

    var htmlContent = `
        <div style="text-align: left !important; font-size: 13px; color: #334155; line-height: 1.5;">
            <p style="color: #475569; font-size: 12px; margin-bottom: 12px; line-height: 1.5;">
                Tentukan <b>Line Kerja</b> untuk perangkat ini. ID & Nama Line akan tersimpan di browser perangkat ini untuk membedakan sesi inspeksi antar Line.
            </p>
            <div style="margin-bottom: 12px;">
                <label style="display: block; font-size: 11px; font-weight: 800; color: #1e293b; text-transform: uppercase; margin-bottom: 5px;">Pilih Line Kerja:</label>
                <select id="swal-line-select" onchange="window.handleSwalLineChange(this)" style="width: 100%; box-sizing: border-box; padding: 10px 12px; font-size: 13px; font-weight: 600; border: 1.5px solid #cbd5e1; border-radius: 8px; background-color: #fff; color: #0f172a; outline: none;">
                    <option value="" disabled ${(!currentLineId && !currentLineName) ? 'selected' : ''}>-- Pilih Line Tempat Kerja --</option>
                    ${optionsHtml}
                </select>
            </div>
            <div id="swal-custom-line-wrapper" style="display: ${isCustomSelected ? 'block' : 'none'}; margin-bottom: 12px; padding: 10px 12px; background-color: #f0fdf4; border: 1.5px dashed #86efac; border-radius: 8px;">
                <label style="display: block; font-size: 11px; font-weight: 800; color: #166534; text-transform: uppercase; margin-bottom: 5px;">Nama Line Baru (Manual):</label>
                <input type="text" id="swal-line-input-custom" value="${isCustomSelected ? currentLineName : ''}" placeholder="Contoh: Line 6, Line Sub-Assy 2, dll" style="width: 100%; box-sizing: border-box; padding: 8px 10px; font-size: 13px; font-weight: 600; border: 1.5px solid #4ade80; border-radius: 6px; background-color: #fff; color: #0f172a; outline: none;">
                <span style="font-size: 10px; color: #15803d; margin-top: 4px; display: block;">* Line baru akan otomatis tersimpan ke master tabel <code>lines</code>.</span>
            </div>
            <div id="swal-line-error-msg" style="display: none; padding: 8px 12px; background-color: #fee2e2; border: 1px solid #fca5a5; color: #991b1b; border-radius: 6px; font-size: 12px; font-weight: 700; margin-bottom: 12px;"></div>
            <div style="display: flex; align-items: center; justify-content: flex-end; gap: 8px; margin-top: 16px;">
                ${cancelBtnHtml}
                <button type="button" id="btn-save-device-line-action" onclick="window.saveDeviceLineModal(this)" style="padding: 10px 22px; background: #2563eb; color: #ffffff; border: none; border-radius: 8px; font-size: 13px; font-weight: 800; cursor: pointer; box-shadow: 0 2px 6px rgba(37,99,235,0.3); transition: all 0.15s;">
                    Simpan Line Perangkat
                </button>
            </div>
        </div>
    `;

    Swal.fire({
        title: '📍 Tentukan Line Device',
        html: htmlContent,
        icon: '',
        showConfirmButton: false,
        showCancelButton: false,
        allowOutsideClick: !!canCancel,
        allowEscapeKey: !!canCancel,
        didOpen: function () {
            var selectEl = document.getElementById('swal-line-select');
            if (selectEl) {
                window.handleSwalLineChange(selectEl);
            }
        }
    });
}
