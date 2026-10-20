<?php
/**
 * Fast Document Preview & Renderer (Zero-Lag + Static HTML Cache)
 * Serves PDF directly, renders XLSX to Grid Table with Images + Fast Cache
 */
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/helper.php';
require_once __DIR__ . '/../../../config/document_helper.php';

$type = trim($_GET['type'] ?? '');
$partCode = trim($_GET['part_code'] ?? '');
$version = isset($_GET['version']) ? (int)$_GET['version'] : 0;
$sheetIndex = isset($_GET['sheet']) ? (int)$_GET['sheet'] : 0;

if ($partCode === '' || $type === '') {
    http_response_code(400);
    echo '<div style="padding: 20px; font-family: sans-serif; color: #64748b; text-align: center;">Parameter dokumen tidak valid.</div>';
    exit;
}

$summary = get_part_documents_summary($partCode);
$docGroup = $summary[$type] ?? null;

if (!$docGroup || !$docGroup['has_document']) {
    http_response_code(404);
    echo '<div style="display:flex; flex-direction:column; align-items:center; justify-content:center; height:100vh; font-family:sans-serif; color:#64748b; background:#f8fafc; text-align:center; padding:20px;">
        <svg style="width:48px; height:48px; color:#cbd5e1; margin-bottom:12px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
        <h3 style="margin:0 0 4px 0; font-size:14px; font-weight:700; color:#334155;">Dokumen Belum Tersedia</h3>
        <p style="margin:0; font-size:12px; color:#94a3b8;">Belum ada file ' . htmlspecialchars($type) . ' untuk Part ' . htmlspecialchars($partCode) . '</p>
    </div>';
    exit;
}

// Select matching version or default to latest
$targetDoc = null;
if ($version > 0) {
    foreach ($docGroup['versions'] as $v) {
        if ($v['version'] === $version) {
            $targetDoc = $v;
            break;
        }
    }
}
if (!$targetDoc) {
    $targetDoc = $docGroup['latest'];
}

$absoluteFilePath = realpath(__DIR__ . '/../../../' . $targetDoc['file_path']);
if (!$absoluteFilePath || !file_exists($absoluteFilePath)) {
    http_response_code(404);
    echo '<div style="padding: 20px; font-family: sans-serif; color: #ef4444; text-align: center;">File dokumen fisik tidak ditemukan di server.</div>';
    exit;
}

$ext = $targetDoc['ext'];

// 1. STREAM PDF DIRECTLY
if ($ext === 'pdf') {
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . basename($absoluteFilePath) . '"');
    header('Content-Length: ' . filesize($absoluteFilePath));
    header('Cache-Control: public, max-age=86400');
    readfile($absoluteFilePath);
    exit;
}

// 2. STREAM IMAGE DIRECTLY
if (in_array($ext, ['png', 'jpg', 'jpeg'])) {
    $mime = ($ext === 'png') ? 'image/png' : 'image/jpeg';
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($absoluteFilePath));
    header('Cache-Control: public, max-age=86400');
    readfile($absoluteFilePath);
    exit;
}

// 3. EXCEL TO HIGH-FIDELITY HTML GRID WITH IMAGES & STATIC CACHE
if (in_array($ext, ['xlsx', 'xls'])) {
    $fileMtime = filemtime($absoluteFilePath);
    $cacheDir = __DIR__ . '/../../../uploads/cache_documents';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0777, true);
    }

    $cacheHash = md5($absoluteFilePath . '_' . $fileMtime . '_sheet' . $sheetIndex);
    $cacheFile = $cacheDir . '/' . $cacheHash . '.html';

    // Fast static cache check (< 5ms response time)
    if (file_exists($cacheFile)) {
        header('Content-Type: text/html; charset=utf-8');
        header('X-OQC-Cache: HIT');
        readfile($cacheFile);
        exit;
    }

    // Require PhpSpreadsheet
    require_once __DIR__ . '/../../../vendor/autoload.php';

    try {
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($absoluteFilePath);
        $reader->setReadDataOnly(false); // Enable reading styles & drawings (images)
        $spreadsheet = $reader->load($absoluteFilePath);

        $sheetCount = $spreadsheet->getSheetCount();
        $sheetNames = $spreadsheet->getSheetNames();

        if ($sheetIndex >= $sheetCount) {
            $sheetIndex = 0;
        }
        $spreadsheet->setActiveSheetIndex($sheetIndex);
        $activeSheet = $spreadsheet->getActiveSheet();
        $activeSheet->setShowGridLines(true); // Preserve Excel Gridlines!

        // Setup HTML Writer
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Html($spreadsheet);
        $writer->writeAllSheets(false);
        $writer->setSheetIndex($sheetIndex);
        $writer->setImagesRoot(realpath(__DIR__ . '/../../../uploads/cache_documents'));

        // Generate Sheet HTML Content
        ob_start();
        $writer->save('php://output');
        $rawHtml = ob_get_clean();

        // Build navigation tabs for multi-sheet workbooks
        $sheetTabsHtml = '';
        if ($sheetCount > 1) {
            $sheetTabsHtml .= '<div style="position: sticky; bottom: 0; left: 0; right: 0; background: #e2e8f0; border-top: 1.5px solid #cbd5e1; display: flex; align-items: center; gap: 4px; padding: 6px 12px; z-index: 99999; overflow-x: auto; font-family: ui-sans-serif, system-ui, sans-serif;">';
            $sheetTabsHtml .= '<span style="font-size: 11px; font-weight: 800; color: #475569; margin-right: 6px; text-transform: uppercase;">Sheets:</span>';
            foreach ($sheetNames as $idx => $sName) {
                $isActive = ($idx === $sheetIndex);
                $tabStyle = $isActive
                    ? 'background: #ffffff; color: #0284c7; border: 1.5px solid #0284c7; font-weight: 800; box-shadow: 0 1px 3px rgba(0,0,0,0.1);'
                    : 'background: #f1f5f9; color: #64748b; border: 1px solid #cbd5e1; font-weight: 600;';
                $tabUrl = base_url('modules/inspection/api/document_preview.php?type=' . urlencode($type) . '&part_code=' . urlencode($partCode) . '&version=' . $version . '&sheet=' . $idx);
                $sheetTabsHtml .= '<a href="' . $tabUrl . '" style="padding: 4px 10px; border-radius: 6px; font-size: 11.5px; text-decoration: none; display: inline-flex; align-items: center; transition: all 0.15s; ' . $tabStyle . '">' . htmlspecialchars($sName) . '</a>';
            }
            $sheetTabsHtml .= '</div>';
        }

        // Custom High-Fidelity Spreadsheet Styling (Preserves exact table box, fonts, and clean scroll)
        $customStyles = '
        <style>
            html, body {
                margin: 0;
                padding: 0;
                background-color: #ffffff;
                font-family: Calibri, Arial, "Segoe UI", sans-serif;
                font-size: 11pt;
                color: #1e293b;
            }
            .excel-workbench-viewport {
                padding: 16px;
                overflow: auto;
                min-width: 100%;
                box-sizing: border-box;
            }
            table {
                border-collapse: collapse !important;
                background-color: #ffffff !important;
                margin: 0 auto;
                box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            }
            td, th {
                border: 1px solid #cbd5e1 !important;
                padding: 4px 8px !important;
                vertical-align: middle;
            }
            /* Custom scrollbar for excel table */
            ::-webkit-scrollbar { width: 8px; height: 8px; }
            ::-webkit-scrollbar-track { background: #f1f5f9; }
            ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
            ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
        </style>';

        // Inject custom styles and sheet tab navigation
        if (strpos($rawHtml, '</head>') !== false) {
            $rawHtml = str_replace('</head>', $customStyles . '</head>', $rawHtml);
        } else {
            $rawHtml = $customStyles . $rawHtml;
        }

        if (strpos($rawHtml, '</body>') !== false) {
            $rawHtml = str_replace('</body>', $sheetTabsHtml . '</body>', $rawHtml);
        } else {
            $rawHtml .= $sheetTabsHtml;
        }

        // Wrap table inside responsive container
        $rawHtml = preg_replace('/(<body[^>]*>)/i', '$1<div class="excel-workbench-viewport">', $rawHtml);
        $rawHtml = str_replace('</body>', '</div></body>', $rawHtml);

        // Save to cache
        @file_put_contents($cacheFile, $rawHtml);

        header('Content-Type: text/html; charset=utf-8');
        header('X-OQC-Cache: MISS');
        echo $rawHtml;
        exit;

    } catch (Exception $e) {
        http_response_code(500);
        echo '<div style="padding: 20px; font-family: sans-serif; color: #ef4444; text-align: center;">
            <p><b>Gagal merender file Excel:</b> ' . htmlspecialchars($e->getMessage()) . '</p>
        </div>';
        exit;
    }
}
