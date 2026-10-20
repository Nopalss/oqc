/**
 * Multi-Document Workbench Viewer Controller
 * Handles tabs for 2D, 3D, PI, Q-Point, PAC, and Standar Packing with Fast Lazy-Loading and Versioning
 */

var activeDocumentTab = '2d';
var currentPartDocuments = window.__INITIAL_PART_DOCS__ || {};
var loadedDocumentUrls = {}; // Cache loaded URLs to prevent redundant network requests

/**
 * Update Document Badges & Availability Dots from Metadata
 */
function refreshDocumentBadges(docsSummary) {
    if (!docsSummary) return;
    currentPartDocuments = docsSummary;

    var docTypes = ['pi', 'qpoint', 'pac', 'standar_packing'];
    docTypes.forEach(function(dt) {
        var dot = document.getElementById('tab-dot-' + dt);
        var info = docsSummary[dt];
        if (dot) {
            if (info && info.has_document) {
                dot.classList.remove('hidden');
                dot.setAttribute('title', info.count + ' versi tersedia (' + (info.latest ? info.latest.version_label_full : '') + ')');
            } else {
                dot.classList.add('hidden');
            }
        }
    });

    // If currently on custom document tab, refresh version dropdown
    if (activeDocumentTab !== '2d' && activeDocumentTab !== '3d') {
        updateVersionDropdownForTab(activeDocumentTab);
    }
}

/**
 * Fetch Part Documents via AJAX on Part Switch or First Scan (Zero-Lag)
 */
function fetchPartDocuments(partCode) {
    if (!partCode) return;
    var url = window.APP_BASE_URL + '/modules/inspection/api/get_part_documents.php?part_code=' + encodeURIComponent(partCode);
    fetch(url)
        .then(function(res) { return res.json(); })
        .then(function(json) {
            if (json && json.success) {
                refreshDocumentBadges(json.documents);
                // If active tab is custom document, reload viewer with new part
                if (activeDocumentTab !== '2d' && activeDocumentTab !== '3d') {
                    loadCustomDocumentViewer(activeDocumentTab);
                }
            }
        })
        .catch(function(err) {
            console.warn('[DocViewer] Gagal memuat metadata dokumen part:', err);
        });
}

/**
 * Switch Active Tab (2d, 3d, pi, qpoint, pac, standar_packing)
 */
function switchDocumentTab(tab) {
    activeDocumentTab = tab;

    var tabThemeMap = {
        '2d': {
            activeBtn: 'bg-blue-600 text-white border-blue-600 shadow-xs',
            inactiveIcon: 'text-blue-600'
        },
        '3d': {
            activeBtn: 'bg-cyan-700 text-white border-cyan-700 shadow-xs',
            inactiveIcon: 'text-cyan-600'
        },
        'pi': {
            activeBtn: 'bg-indigo-600 text-white border-indigo-600 shadow-xs',
            inactiveIcon: 'text-indigo-600'
        },
        'qpoint': {
            activeBtn: 'bg-amber-600 text-white border-amber-600 shadow-xs',
            inactiveIcon: 'text-amber-500'
        },
        'pac': {
            activeBtn: 'bg-emerald-600 text-white border-emerald-600 shadow-xs',
            inactiveIcon: 'text-emerald-600'
        },
        'standar_packing': {
            activeBtn: 'bg-orange-700 text-white border-orange-700 shadow-xs',
            inactiveIcon: 'text-orange-600'
        }
    };

    var tabColors = {
        '2d': { bg: '#2563eb', border: '#2563eb' },
        '3d': { bg: '#0e7490', border: '#0e7490' },
        'pi': { bg: '#4f46e5', border: '#4f46e5' },
        'qpoint': { bg: '#d97706', border: '#d97706' },
        'pac': { bg: '#059669', border: '#059669' },
        'standar_packing': { bg: '#c2410c', border: '#c2410c' }
    };

    var allTabs = ['2d', '3d', 'pi', 'qpoint', 'pac', 'standar_packing'];
    allTabs.forEach(function(t) {
        var btn = document.getElementById('tab-btn-' + t);
        if (!btn) return;
        var icon = btn.querySelector('svg');

        if (t === tab) {
            btn.className = 'px-3 py-1.5 rounded-lg text-xs font-bold transition-all border shadow-xs flex items-center whitespace-nowrap cursor-pointer doc-tab-active-' + t;
            var c = tabColors[t] || { bg: '#2563eb', border: '#2563eb' };
            btn.setAttribute('style', 'background-color: ' + c.bg + ' !important; border-color: ' + c.border + ' !important; color: #ffffff !important;');
            if (icon) {
                icon.className = 'w-3.5 h-3.5 mr-1.5 flex-shrink-0 text-white';
                icon.setAttribute('style', 'color: #ffffff !important; stroke: #ffffff !important;');
            }
        } else {
            btn.className = 'px-3 py-1.5 rounded-lg text-xs font-bold transition-all bg-slate-100 text-slate-700 border border-slate-300 shadow-2xs flex items-center whitespace-nowrap cursor-pointer doc-tab-hover-' + t;
            btn.removeAttribute('style');
            if (icon) {
                icon.className = 'w-3.5 h-3.5 mr-1.5 flex-shrink-0 doc-icon-' + t;
                icon.removeAttribute('style');
            }
        }
    });

    var box2d = document.getElementById('viewport-2d');
    var box3d = document.getElementById('viewport-3d');
    var boxCustom = document.getElementById('viewport-custom-doc');
    var versionContainer = document.getElementById('doc-version-selector-container');

    // Reset zoom level on tab change
    resetZoom();

    if (tab === '2d') {
        box2d.classList.remove('hidden');
        box3d.classList.add('hidden');
        boxCustom.classList.add('hidden');
        if (versionContainer) versionContainer.classList.add('hidden');
    } else if (tab === '3d') {
        box3d.classList.remove('hidden');
        box2d.classList.add('hidden');
        boxCustom.classList.add('hidden');
        if (versionContainer) versionContainer.classList.add('hidden');

        if (typeof is3dViewerInitialized !== 'undefined' && !is3dViewerInitialized && typeof init3dViewer === 'function') {
            init3dViewer();
        }
    } else {
        // Custom Documents (pi, qpoint, pac, standar_packing)
        box2d.classList.add('hidden');
        box3d.classList.add('hidden');
        boxCustom.classList.remove('hidden');

        updateVersionDropdownForTab(tab);
        loadCustomDocumentViewer(tab);
    }
}

// Backward compatibility alias for existing code calling switchDrawingTab
function switchDrawingTab(tab) {
    switchDocumentTab(tab);
}

/**
 * Populate Version Dropdown for the Selected Document
 */
function updateVersionDropdownForTab(docType) {
    var versionContainer = document.getElementById('doc-version-selector-container');
    var select = document.getElementById('doc-version-select');
    if (!versionContainer || !select) return;

    var docInfo = currentPartDocuments[docType];
    if (!docInfo || !docInfo.has_document || !docInfo.versions || docInfo.versions.length <= 1) {
        // Only 1 version or no version: show single label or hide dropdown
        if (docInfo && docInfo.has_document && docInfo.latest) {
            select.innerHTML = '<option value="' + escapeHtml(docInfo.latest.filename) + '">' + escapeHtml(docInfo.latest.version_label_full) + '</option>';
            versionContainer.classList.remove('hidden');
        } else {
            versionContainer.classList.add('hidden');
        }
        return;
    }

    // Multiple versions available
    var html = '';
    docInfo.versions.forEach(function(v) {
        html += '<option value="' + escapeHtml(v.filename) + '">' + escapeHtml(v.version_label_full) + '</option>';
    });
    select.innerHTML = html;
    versionContainer.classList.remove('hidden');
}

/**
 * Handle Version Dropdown Change
 */
function onDocumentVersionChange(fileKey) {
    if (activeDocumentTab !== '2d' && activeDocumentTab !== '3d') {
        loadCustomDocumentViewer(activeDocumentTab, fileKey);
    }
}

/**
 * Lazy Load Document into Isolated Iframe with Loading Overlay
 */
function loadCustomDocumentViewer(docType, targetVersion) {
    var iframe = document.getElementById('custom-doc-frame');
    var placeholder = document.getElementById('custom-doc-placeholder');
    var loading = document.getElementById('custom-doc-loading');
    var pTitle = document.getElementById('custom-doc-placeholder-title');
    var pDesc = document.getElementById('custom-doc-placeholder-desc');
    var pFolder = document.getElementById('custom-doc-placeholder-folder');

    // Robust Part Code resolution: check global session state, inputs, and card elements
    var partCode = '';
    if (typeof currentSessionPartCode !== 'undefined' && currentSessionPartCode && currentSessionPartCode !== '-') {
        partCode = String(currentSessionPartCode).trim();
    } else if (typeof currentSessionObj !== 'undefined' && currentSessionObj && currentSessionObj.part_code) {
        partCode = String(currentSessionObj.part_code).trim();
    } else {
        var pCodeElem = document.getElementById('card-part-code') || document.getElementById('selected-plan-part-code');
        partCode = pCodeElem ? (pCodeElem.textContent || pCodeElem.innerText).trim() : '';
    }

    // Fallback if still empty or '-'
    if (partCode === '-' || partCode === '') {
        var inputCode = document.getElementById('scan-part-code');
        if (inputCode && inputCode.value) {
            partCode = inputCode.value.trim();
        }
    }

    var docInfo = currentPartDocuments[docType];
    var folderName = docInfo ? docInfo.folder : docType.toUpperCase();

    if (pFolder) pFolder.textContent = folderName;

    if (!docInfo || !docInfo.has_document || !partCode) {
        // Document does not exist
        if (iframe) {
            iframe.style.display = 'none';
            iframe.src = 'about:blank';
        }
        if (placeholder) {
            placeholder.style.display = 'flex';
            if (pTitle) pTitle.textContent = (docInfo ? docInfo.full_label : docType.toUpperCase()) + ' BELUM TERSEDIA';
            if (pDesc) pDesc.textContent = 'File belum diunggah untuk Part Code ' + (partCode || '-') + ' di folder uploads/documents/' + folderName + '/';
        }
        if (loading) loading.classList.add('hidden');
        return;
    }

    // Determine target version
    var targetDoc = null;
    var targetFileKey = (typeof targetVersion === 'string') ? targetVersion : null;

    if (!targetFileKey) {
        var select = document.getElementById('doc-version-select');
        if (select && select.value) {
            targetFileKey = select.value;
        }
    }

    if (targetFileKey) {
        docInfo.versions.forEach(function(v) {
            if (v.filename === targetFileKey || String(v.version) === String(targetFileKey)) {
                targetDoc = v;
            }
        });
    }

    if (!targetDoc) targetDoc = docInfo.latest;

    var previewUrl = window.APP_BASE_URL + '/modules/inspection/api/document_preview.php?type=' + encodeURIComponent(docType) +
        '&part_code=' + encodeURIComponent(partCode) +
        '&file=' + encodeURIComponent(targetDoc ? targetDoc.filename : '') +
        '&version=' + (targetDoc ? targetDoc.version : 0);

    // If PDF, add #toolbar=0 for sleek zero-scroll view
    if (targetDoc && targetDoc.ext === 'pdf') {
        previewUrl += '#toolbar=0';
    }

    // Show loading
    if (loading) loading.classList.remove('hidden');
    if (placeholder) placeholder.style.display = 'none';

    if (iframe) {
        iframe.style.display = 'block';

        // Check if iframe already displays this exact previewUrl
        if (iframe.getAttribute('data-current-url') !== previewUrl) {
            iframe.setAttribute('data-current-url', previewUrl);
            iframe.onload = function() {
                if (loading) loading.classList.add('hidden');
            };
            iframe.onerror = function() {
                if (loading) loading.classList.add('hidden');
            };
            iframe.src = previewUrl;
        } else {
            if (loading) loading.classList.add('hidden');
        }
    }
}

// Auto initialize document badges when page finishes rendering
document.addEventListener('DOMContentLoaded', function() {
    refreshDocumentBadges(window.__INITIAL_PART_DOCS__);
});
