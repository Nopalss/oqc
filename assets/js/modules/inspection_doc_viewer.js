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

    var allTabs = ['2d', '3d', 'pi', 'qpoint', 'pac', 'standar_packing'];
    allTabs.forEach(function(t) {
        var btn = document.getElementById('tab-btn-' + t);
        if (!btn) return;
        if (t === tab) {
            btn.className = 'px-2.5 py-1 rounded-lg text-xs font-bold transition-all bg-blue-600 text-white shadow-xs flex items-center whitespace-nowrap';
        } else {
            btn.className = 'px-2.5 py-1 rounded-lg text-xs font-bold transition-all text-slate-700 hover:text-slate-900 hover:bg-slate-300/50 flex items-center whitespace-nowrap';
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
            select.innerHTML = '<option value="' + docInfo.latest.version + '">' + docInfo.latest.version_label_full + '</option>';
            versionContainer.classList.remove('hidden');
        } else {
            versionContainer.classList.add('hidden');
        }
        return;
    }

    // Multiple versions available
    var html = '';
    docInfo.versions.forEach(function(v) {
        html += '<option value="' + v.version + '">' + v.version_label_full + '</option>';
    });
    select.innerHTML = html;
    versionContainer.classList.remove('hidden');
}

/**
 * Handle Version Dropdown Change
 */
function onDocumentVersionChange(versionVal) {
    if (activeDocumentTab !== '2d' && activeDocumentTab !== '3d') {
        loadCustomDocumentViewer(activeDocumentTab, parseInt(versionVal, 10));
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

    var pCodeElem = document.getElementById('card-part-code') || document.getElementById('selected-plan-part-code');
    var partCode = pCodeElem ? (pCodeElem.textContent || pCodeElem.innerText).trim() : '';

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
    var ver = targetVersion;
    if (!ver) {
        var select = document.getElementById('doc-version-select');
        ver = select && select.value ? parseInt(select.value, 10) : (docInfo.latest ? docInfo.latest.version : 1);
    }

    var targetDoc = null;
    docInfo.versions.forEach(function(v) {
        if (v.version === ver) targetDoc = v;
    });
    if (!targetDoc) targetDoc = docInfo.latest;

    var previewUrl = window.APP_BASE_URL + '/modules/inspection/api/document_preview.php?type=' + encodeURIComponent(docType) +
        '&part_code=' + encodeURIComponent(partCode) +
        '&version=' + (targetDoc ? targetDoc.version : 1);

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
