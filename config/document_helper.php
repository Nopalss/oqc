<?php
/**
 * OQC Document Helper (PDF Only Edition - Precision Match & Revision Parser)
 * Handles exact part code isolation, versioning (_v1, _v2), and drawing revisions (_00, _01).
 */

if (!function_exists('get_document_types_config')) {
    function get_document_types_config() {
        return [
            'pi' => [
                'code' => 'pi',
                'label' => 'PI',
                'full_label' => 'Process Inspection (PI)',
                'folder' => 'PI',
                'icon' => '<svg class="w-3.5 h-3.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414A1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>',
            ],
            'qpoint' => [
                'code' => 'qpoint',
                'label' => 'Q-Point',
                'full_label' => 'Quality Point (Q-Point)',
                'folder' => 'Q-Point',
                'icon' => '<svg class="w-3.5 h-3.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>',
            ],
            'pac' => [
                'code' => 'pac',
                'label' => 'PAC',
                'full_label' => 'Part Approval Certificate (PAC)',
                'folder' => 'PAC',
                'icon' => '<svg class="w-3.5 h-3.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path></svg>',
            ],
            'standar_packing' => [
                'code' => 'standar_packing',
                'label' => 'Standar Packing',
                'full_label' => 'Standar Packing',
                'folder' => 'Standar_Packing',
                'icon' => '<svg class="w-3.5 h-3.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path></svg>',
            ],
        ];
    }
}

/**
 * Scan uploads/documents/ for all available PDF documents for a specific Part Code.
 * 
 * Strict Isolation:
 *   Ensures part code '1' ONLY matches '1_00.pdf', '1_v1.pdf', '1.pdf', etc.
 *   Never matches '187385503_00.pdf' or '101_v1.pdf'!
 * 
 * Supported patterns:
 *   [PartCode]_00.pdf / [PartCode]-01.pdf   (Standard Drawing Revisions)
 *   [PartCode]_v1.pdf / [PartCode]-v2.pdf   (Version format)
 *   [PartCode]_rev01.pdf                    (Rev format)
 *   [PartCode].pdf                          (Base format)
 */
if (!function_exists('get_part_documents_summary')) {
    function get_part_documents_summary($partCode) {
        $cleanPartCode = trim((string)$partCode);
        $configs = get_document_types_config();
        $result = [];

        $baseUploadDir = realpath(__DIR__ . '/../uploads/documents');

        foreach ($configs as $docKey => $cfg) {
            $folderName = $cfg['folder'];
            $docFolder = $baseUploadDir ? ($baseUploadDir . DIRECTORY_SEPARATOR . $folderName) : null;
            
            $versions = [];

            if ($cleanPartCode !== '' && $docFolder && is_dir($docFolder)) {
                // Read all PDF files in this directory to perform strict regex matching
                $allPdfFiles = glob($docFolder . DIRECTORY_SEPARATOR . '*.pdf');

                if (!empty($allPdfFiles)) {
                    // Regex boundary: Part Code must be followed by [_-](prefix)(number) OR end with .pdf
                    $quotedCode = preg_quote($cleanPartCode, '/');
                    $pattern = '/^' . $quotedCode . '(?:[_-](v(?:er)?|rev)?([0-9]+))?\.pdf$/i';

                    foreach ($allPdfFiles as $filePath) {
                        $filename = basename($filePath);

                        if (preg_match($pattern, $filename, $matches)) {
                            $prefix = strtolower($matches[1] ?? '');
                            $rawNum = $matches[2] ?? null;

                            // Determine numeric order and display label
                            if ($rawNum !== null) {
                                $versionOrder = (int)$rawNum;
                                if ($prefix === 'v' || $prefix === 'ver') {
                                    $versionLabel = 'v' . $rawNum;
                                } elseif ($prefix === 'rev') {
                                    $versionLabel = 'Rev ' . $rawNum;
                                } else {
                                    // Numeric revision like _00, _01
                                    $versionLabel = 'Revisi ' . $rawNum;
                                }
                            } else {
                                // Default / base file like PartCode.pdf
                                $versionOrder = 0;
                                $versionLabel = 'Revisi 00';
                            }

                            $relativeRelPath = 'uploads/documents/' . $folderName . '/' . $filename;

                            $versions[] = [
                                'version' => $versionOrder,
                                'version_key' => $filename,
                                'version_label' => $versionLabel,
                                'filename' => $filename,
                                'ext' => 'pdf',
                                'format' => 'pdf',
                                'file_path' => $relativeRelPath,
                                'file_size' => filesize($filePath),
                                'modified_at' => filemtime($filePath),
                            ];
                        }
                    }
                }
            }

            // Sort versions descending: latest revision first (e.g., 02 > 01 > 00 or v2 > v1)
            usort($versions, function ($a, $b) {
                if ($a['version'] === $b['version']) {
                    return $b['modified_at'] - $a['modified_at'];
                }
                return $b['version'] - $a['version'];
            });

            // Mark latest version
            if (!empty($versions)) {
                foreach ($versions as $idx => &$v) {
                    $v['is_latest'] = ($idx === 0);
                    $v['version_label_full'] = $v['version_label'] . ($v['is_latest'] ? ' (Terbaru)' : '');
                }
                unset($v);
            }

            $hasDoc = !empty($versions);
            $latestDoc = $hasDoc ? $versions[0] : null;

            $result[$docKey] = [
                'type' => $docKey,
                'label' => $cfg['label'],
                'full_label' => $cfg['full_label'],
                'folder' => $cfg['folder'],
                'has_document' => $hasDoc,
                'count' => count($versions),
                'latest' => $latestDoc,
                'versions' => $versions,
            ];
        }

        return $result;
    }
}
