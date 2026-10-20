<?php
/**
 * Ultra-Fast Native PDF Streamer
 * Zero-overhead, zero-dependency, streaming directly to browser PDF viewer
 */
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../config/document_helper.php';

$type = trim($_GET['type'] ?? '');
$partCode = trim($_GET['part_code'] ?? '');
$version = isset($_GET['version']) ? (int)$_GET['version'] : 0;

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
        <p style="margin:0; font-size:12px; color:#94a3b8;">Belum ada file PDF ' . htmlspecialchars($type) . ' untuk Part ' . htmlspecialchars($partCode) . '</p>
    </div>';
    exit;
}

$targetFileParam = trim($_GET['file'] ?? '');

// Select matching version or default to latest
$targetDoc = null;

// 1. Match by exact filename if provided
if ($targetFileParam !== '') {
    foreach ($docGroup['versions'] as $v) {
        if ($v['filename'] === $targetFileParam) {
            $targetDoc = $v;
            break;
        }
    }
}

// 2. Match by numeric version if file not specified
if (!$targetDoc && isset($_GET['version'])) {
    $vInt = (int)$_GET['version'];
    foreach ($docGroup['versions'] as $v) {
        if ($v['version'] === $vInt) {
            $targetDoc = $v;
            break;
        }
    }
}

// 3. Fallback to latest
if (!$targetDoc) {
    $targetDoc = $docGroup['latest'];
}

$absoluteFilePath = realpath(__DIR__ . '/../../../' . $targetDoc['file_path']);
if (!$absoluteFilePath || !file_exists($absoluteFilePath)) {
    http_response_code(404);
    echo '<div style="padding: 20px; font-family: sans-serif; color: #ef4444; text-align: center;">File PDF tidak ditemukan di server.</div>';
    exit;
}

// Stream Native PDF
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . basename($absoluteFilePath) . '"');
header('Content-Length: ' . filesize($absoluteFilePath));
header('Cache-Control: public, max-age=86400');
readfile($absoluteFilePath);
exit;
