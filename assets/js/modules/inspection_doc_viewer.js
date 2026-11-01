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
function refreshDocumentBadges(docsSummary, partCode) {
    if (!docsSummary) return;
    currentPartDocuments = docsSummary;

    var curPart = partCode || '';
    if (!curPart && typeof currentSessionPartCode !== 'undefined' && currentSessionPartCode && currentSessionPartCode !== '-') {
        curPart = String(currentSessionPartCode).trim();
    }

    // 1. Sync Tab Dot Indicators
    var docTypes = ['pi', 'qpoint', 'pac', 'standar_packing', 'mi'];
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

    // Sync Upload Redirect Buttons to current active part
    syncUploadRedirectButtons(curPart);

    // 2. Sync 2D Viewport from uploads/parts/{PartCode}/
    var doc2d = docsSummary['2d'];
    var pdfFrame = document.getElementById('pdf-frame');
    var p2dBox = document.getElementById('drawing-placeholder-box');
    var p2dText = document.getElementById('placeholder-part-code-text');
    var p2dFolder = document.getElementById('placeholder-part-folder');
    if (p2dText && curPart) p2dText.textContent = curPart;
    if (p2dFolder && curPart) p2dFolder.textContent = curPart;

    if (doc2d && doc2d.has_document && doc2d.latest) {
        var url2d = (doc2d.latest.full_url || (window.APP_BASE_URL + '/' + doc2d.latest.file_path)) + '#toolbar=0';
        if (pdfFrame) {
            pdfFrame.onerror = function() {
                pdfFrame.style.display = 'none';
                if (p2dBox) p2dBox.style.display = 'flex';
            };
            if (pdfFrame.getAttribute('data-loaded-src') !== url2d) {
                pdfFrame.setAttribute('data-loaded-src', url2d);
                pdfFrame.src = url2d;
            }
            pdfFrame.style.display = 'block';
        }
        if (p2dBox) p2dBox.style.display = 'none';
    } else {
        if (pdfFrame) {
            pdfFrame.removeAttribute('data-loaded-src');
            pdfFrame.src = '';
            pdfFrame.style.display = 'none';
        }
        if (p2dBox) p2dBox.style.display = 'flex';
    }

    // 3. Sync 3D Viewport from uploads/parts/{PartCode}/
    var doc3d = docsSummary['3d'];
    var cad3dViewport = document.getElementById('cad-3d-viewport');
    var p3dBox = document.getElementById('cad-3d-placeholder-box');
    var p3dText = document.getElementById('placeholder-3d-part-code-text');
    var p3dFolder = document.getElementById('placeholder-3d-part-folder');
    if (p3dText && curPart) p3dText.textContent = curPart;
    if (p3dFolder && curPart) p3dFolder.textContent = curPart;

    if (doc3d && doc3d.has_document && doc3d.latest) {
        var url3d = doc3d.latest.full_url || (window.APP_BASE_URL + '/' + doc3d.latest.file_path);
        if (cad3dViewport) {
            cad3dViewport.setAttribute('data-stp-url', url3d);
            cad3dViewport.style.display = 'block';
        }
        if (p3dBox) p3dBox.style.display = 'none';
        if (activeDocumentTab === '3d' && typeof is3dViewerInitialized !== 'undefined' && !is3dViewerInitialized && typeof init3dViewer === 'function') {
            init3dViewer();
        }
    } else {
        if (cad3dViewport) {
            cad3dViewport.removeAttribute('data-stp-url');
            cad3dViewport.style.display = 'none';
        }
        if (p3dBox) p3dBox.style.display = 'flex';
    }

    // 4. If currently on custom document tab or version-enabled tab, refresh version dropdown
    updateVersionDropdownForTab(activeDocumentTab);
}

/**
 * Fetch Part Documents via AJAX on Part Switch, Tab Click, or Manual Trigger (Zero-Lag & Zero-Overhead)
 */
function fetchPartDocuments(partCode, onComplete) {
    var pCode = partCode || getActivePartCode();
    if (!pCode) {
        if (typeof onComplete === 'function') onComplete(false, null);
        return;
    }
    // Add cache-busting timestamp query to guarantee fresh file detection from server folder
    var url = window.APP_BASE_URL + '/modules/inspection/api/get_part_documents.php?part_code=' + encodeURIComponent(pCode) + '&_t=' + Date.now();
    fetch(url)
        .then(function(res) { return res.json(); })
        .then(function(json) {
            if (json && json.success) {
                refreshDocumentBadges(json.documents, pCode);
                if (typeof onComplete === 'function') {
                    onComplete(true, json.documents);
                }
            } else {
                if (typeof onComplete === 'function') onComplete(false, null);
            }
        })
        .catch(function(err) {
            console.warn('[DocViewer] Gagal memuat metadata dokumen part:', err);
            if (typeof onComplete === 'function') onComplete(false, null);
        });
}

/**
 * Robust Part Code resolution helper: checks global session state, inputs, and card elements
 */
function getActivePartCode() {
    var partCode = '';
    if (typeof currentSessionPartCode !== 'undefined' && currentSessionPartCode && currentSessionPartCode !== '-') {
        partCode = String(currentSessionPartCode).trim();
    } else if (typeof currentSessionObj !== 'undefined' && currentSessionObj && currentSessionObj.part_code) {
        partCode = String(currentSessionObj.part_code).trim();
    } else {
        var pCodeElem = document.getElementById('card-part-code') || document.getElementById('selected-plan-part-code');
        partCode = pCodeElem ? (pCodeElem.textContent || pCodeElem.innerText).trim() : '';
    }

    if (partCode === '-' || partCode === '') {
        var inputCode = document.getElementById('scan-part-code');
        if (inputCode && inputCode.value) {
            partCode = inputCode.value.trim();
        }
    }
    return (partCode === '-' ? '' : partCode);
}

/**
 * Sync direct upload redirect buttons across viewports
 */
function syncUploadRedirectButtons(partCode) {
    var pCode = partCode || getActivePartCode();
    if (!pCode) return;
    var uploadUrl = (window.APP_BASE_URL || '') + '/modules/master_drawings/upload.php?part_code=' + encodeURIComponent(pCode);
    
    var btn2d = document.getElementById('btn-upload-redirect-2d');
    var btn3d = document.getElementById('btn-upload-redirect-3d');
    var btnCustom = document.getElementById('btn-upload-redirect-custom');
    
    if (btn2d) {
        btn2d.href = uploadUrl;
        btn2d.style.display = 'inline-flex';
    }
    if (btn3d) {
        btn3d.href = uploadUrl;
        btn3d.style.display = 'inline-flex';
    }
    if (btnCustom) {
        btnCustom.href = uploadUrl;
        btnCustom.style.display = 'inline-flex';
    }

    var p2dText = document.getElementById('placeholder-part-code-text');
    var p2dFolder = document.getElementById('placeholder-part-folder');
    var p3dText = document.getElementById('placeholder-3d-part-code-text');
    var p3dFolder = document.getElementById('placeholder-3d-part-folder');
    var pCustomFolder = document.getElementById('custom-doc-placeholder-folder');

    if (p2dText) p2dText.textContent = pCode;
    if (p2dFolder) p2dFolder.textContent = pCode;
    if (p3dText) p3dText.textContent = pCode;
    if (p3dFolder) p3dFolder.textContent = pCode;
    if (pCustomFolder) pCustomFolder.textContent = pCode;
}

/**
 * Switch Active Tab (2d, 3d, pi, qpoint, pac, standar_packing, mi)
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
        },
        'mi': {
            activeBtn: 'bg-cyan-700 text-white border-cyan-700 shadow-xs',
            inactiveIcon: 'text-cyan-600'
        },
        'aql': {
            activeBtn: 'bg-purple-600 text-white border-purple-600 shadow-xs',
            inactiveIcon: 'text-purple-600'
        }
    };

    var tabColors = {
        '2d': { bg: '#2563eb', border: '#2563eb' },
        '3d': { bg: '#0e7490', border: '#0e7490' },
        'pi': { bg: '#4f46e5', border: '#4f46e5' },
        'qpoint': { bg: '#d97706', border: '#d97706' },
        'pac': { bg: '#059669', border: '#059669' },
        'standar_packing': { bg: '#c2410c', border: '#c2410c' },
        'mi': { bg: '#0891b2', border: '#0891b2' },
        'aql': { bg: '#7c3aed', border: '#7c3aed' }
    };

    var allTabs = ['2d', '3d', 'aql', 'pi', 'qpoint', 'pac', 'standar_packing', 'mi'];
    allTabs.forEach(function(t) {
        var btn = document.getElementById('tab-btn-' + t);
        if (!btn) return;
        var icon = btn.querySelector('svg');

        if (t === tab) {
            btn.className = 'px-3 py-1.5 rounded-lg text-xs font-bold transition-all border shadow-xs flex items-center whitespace-nowrap cursor-pointer doc-tab-active-' + t;
            var c = tabColors[t] || { bg: '#2563eb', border: '#2563eb' };
            btn.setAttribute('style', 'flex-shrink: 0 !important; white-space: nowrap !important; background-color: ' + c.bg + ' !important; border-color: ' + c.border + ' !important; color: #ffffff !important;');
            if (icon) {
                icon.className = 'w-3.5 h-3.5 mr-1.5 flex-shrink-0 text-white';
                icon.setAttribute('style', 'color: #ffffff !important; stroke: #ffffff !important;');
            }
        } else {
            btn.className = 'px-3 py-1.5 rounded-lg text-xs font-bold transition-all bg-slate-100 text-slate-700 border border-slate-300 shadow-2xs flex items-center whitespace-nowrap cursor-pointer doc-tab-hover-' + t;
            btn.setAttribute('style', 'flex-shrink: 0 !important; white-space: nowrap !important;');
            if (icon) {
                icon.className = 'w-3.5 h-3.5 mr-1.5 flex-shrink-0 doc-icon-' + t;
                icon.removeAttribute('style');
            }
        }
    });

    var box2d = document.getElementById('viewport-2d');
    var box3d = document.getElementById('viewport-3d');
    var boxCustom = document.getElementById('viewport-custom-doc');
    var boxAql = document.getElementById('viewport-aql');
    var versionContainer = document.getElementById('doc-version-selector-container');

    // Reset zoom level on tab change
    resetZoom();

    if (tab === '2d') {
        if (box2d) box2d.classList.remove('hidden');
        if (box3d) box3d.classList.add('hidden');
        if (boxCustom) boxCustom.classList.add('hidden');
        if (boxAql) boxAql.classList.add('hidden');
        updateVersionDropdownForTab('2d');
        syncUploadRedirectButtons();

        // On-demand: Re-check server folder if 2D was previously not available
        var cur2d = currentPartDocuments['2d'];
        if (!cur2d || !cur2d.has_document) {
            fetchPartDocuments(getActivePartCode(), function(ok) {
                if (ok && activeDocumentTab === '2d') {
                    updateVersionDropdownForTab('2d');
                }
            });
        }
    } else if (tab === '3d') {
        if (box3d) box3d.classList.remove('hidden');
        if (box2d) box2d.classList.add('hidden');
        if (boxCustom) boxCustom.classList.add('hidden');
        if (boxAql) boxAql.classList.add('hidden');
        updateVersionDropdownForTab('3d');
        syncUploadRedirectButtons();

        // On-demand: Re-check server folder if 3D was previously not available
        var cur3d = currentPartDocuments['3d'];
        if (!cur3d || !cur3d.has_document) {
            fetchPartDocuments(getActivePartCode(), function(ok) {
                if (ok && activeDocumentTab === '3d') {
                    updateVersionDropdownForTab('3d');
                }
            });
        }

        if (typeof is3dViewerInitialized !== 'undefined' && !is3dViewerInitialized && typeof init3dViewer === 'function') {
            init3dViewer();
        }
    } else if (tab === 'aql') {
        // Tab AQL: Global Standard Document from table standard_oqc id = 1
        if (boxAql) boxAql.classList.remove('hidden');
        if (box2d) box2d.classList.add('hidden');
        if (box3d) box3d.classList.add('hidden');
        if (boxCustom) boxCustom.classList.add('hidden');

        // AQL is a global standard document without part code versioning
        if (versionContainer) versionContainer.classList.add('hidden');
    } else {
        // Custom Documents (pi, qpoint, pac, standar_packing, mi)
        if (box2d) box2d.classList.add('hidden');
        if (box3d) box3d.classList.add('hidden');
        if (boxAql) boxAql.classList.add('hidden');
        if (boxCustom) boxCustom.classList.remove('hidden');

        updateVersionDropdownForTab(tab);
        loadCustomDocumentViewer(tab);

        // On-demand: Re-check server folder if clicked document was previously not available
        var curDoc = currentPartDocuments[tab];
        if (!curDoc || !curDoc.has_document) {
            fetchPartDocuments(getActivePartCode(), function(ok, updatedDocs) {
                if (ok && activeDocumentTab === tab) {
                    var newDocInfo = updatedDocs ? updatedDocs[tab] : null;
                    if (newDocInfo && newDocInfo.has_document) {
                        updateVersionDropdownForTab(tab);
                        loadCustomDocumentViewer(tab);
                    }
                }
            });
        }
    }
}

// Backward compatibility alias for existing code calling switchDrawingTab
function switchDrawingTab(tab) {
    switchDocumentTab(tab);
}

/**
 * Populate Version Dropdown for the Selected Document (Supports 2D, 3D, PI, Q-Point, PAC, Standar Packing)
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
 * Handle Version Dropdown Change (2D, 3D, and QC Documents)
 */
function onDocumentVersionChange(fileKey) {
    if (!fileKey) return;

    if (activeDocumentTab === '2d') {
        var doc2d = currentPartDocuments['2d'];
        if (doc2d && doc2d.versions) {
            var selectedVersion = null;
            doc2d.versions.forEach(function(v) {
                if (v.filename === fileKey) selectedVersion = v;
            });
            if (selectedVersion) {
                var iframe = document.getElementById('pdf-frame');
                var placeholder = document.getElementById('drawing-placeholder-box');
                if (iframe) {
                    var targetUrl = (selectedVersion.full_url || (window.APP_BASE_URL + '/' + selectedVersion.file_path)) + '#toolbar=0';
                    iframe.src = targetUrl;
                    iframe.style.display = 'block';
                }
                if (placeholder) placeholder.style.display = 'none';
            }
        }
    } else if (activeDocumentTab === '3d') {
        var doc3d = currentPartDocuments['3d'];
        if (doc3d && doc3d.versions) {
            var selectedVersion3d = null;
            doc3d.versions.forEach(function(v) {
                if (v.filename === fileKey) selectedVersion3d = v;
            });
            if (selectedVersion3d) {
                var container = document.getElementById('cad-3d-viewport');
                var targetStpUrl = selectedVersion3d.full_url || (window.APP_BASE_URL + '/' + selectedVersion3d.file_path);
                if (container) {
                    container.setAttribute('data-stp-url', targetStpUrl);
                    container.style.display = 'block';
                }
                var p3d = document.getElementById('cad-3d-placeholder-box');
                if (p3d) p3d.style.display = 'none';

                if (typeof renderCadStep === 'function') {
                    renderCadStep('cad-3d-viewport', targetStpUrl);
                } else if (typeof init3dViewer === 'function') {
                    init3dViewer();
                }
            }
        }
    } else {
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

    var partCode = getActivePartCode();
    var docInfo = currentPartDocuments[docType];
    var folderName = docInfo ? docInfo.folder : docType.toUpperCase();

    if (pFolder) pFolder.textContent = partCode || '-';

    var btnCustomUpload = document.getElementById('btn-upload-redirect-custom');
    var btnCustomText = document.getElementById('btn-upload-redirect-text');
    if (btnCustomUpload && partCode) {
        btnCustomUpload.href = (window.APP_BASE_URL || '') + '/modules/master_drawings/upload.php?part_code=' + encodeURIComponent(partCode);
    }
    if (btnCustomText) {
        btnCustomText.textContent = '+ Upload Berkas ' + (docInfo ? docInfo.label : docType.toUpperCase());
    }

    if (!docInfo || !docInfo.has_document || !partCode) {
        // Document does not exist
        if (iframe) {
            iframe.style.display = 'none';
            iframe.src = 'about:blank';
        }
        if (placeholder) {
            placeholder.style.display = 'flex';
            var displayLabel = (docInfo && docInfo.full_label) ? docInfo.full_label : (docType === 'pi' ? 'Pedoman Inspeksi (PI)' : docType.toUpperCase());
            if (pTitle) pTitle.textContent = displayLabel + ' BELUM TERSEDIA';
            if (pDesc) pDesc.textContent = 'File belum diunggah untuk Part Code ' + (partCode || '-') + ' di folder uploads/parts/' + (partCode || '-') + '/';
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

/**
 * Instant Manual Document Reload (Without Page Refresh)
 */
function reloadDocViewer() {
    var pCode = getActivePartCode();
    if (!pCode) return;
    fetchPartDocuments(pCode, function(ok, updatedDocs) {
        if (ok) {
            updateVersionDropdownForTab(activeDocumentTab);
            if (activeDocumentTab !== '2d' && activeDocumentTab !== '3d') {
                loadCustomDocumentViewer(activeDocumentTab);
            }
        }
    });
}

// Auto initialize document badges and upload buttons when page finishes rendering
document.addEventListener('DOMContentLoaded', function() {
    var pCode = getActivePartCode();
    refreshDocumentBadges(window.__INITIAL_PART_DOCS__, pCode);
    syncUploadRedirectButtons(pCode);
});

// Passive check when returning to Workbench tab (0ms background overhead)
window.addEventListener('focus', function() {
    var curDoc = currentPartDocuments ? currentPartDocuments[activeDocumentTab] : null;
    // Only check server if currently viewing a tab whose document was missing
    if (!curDoc || !curDoc.has_document) {
        reloadDocViewer();
    }
});
