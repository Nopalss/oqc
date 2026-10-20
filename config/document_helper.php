<?php
/**
 * OQC Document Helper
 * Handles discovery, versioning, and caching for PI, Q-Point, PAC, and Standar Packing documents.
 */

if (!function_exists('get_document_types_config')) {
    function get_document_types_config() {
        return [
            'pi' => [
                'code' => 'pi',
                'label' => 'PI',
                'full_label' => 'Process Inspection (PI)',
                'folder' => 'PI',
                'icon' => '<svg class="w-3.5 h-3.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>',
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
 * Scan uploads/documents/ for all available documents for a specific Part Code.
 * Supports patterns:
 *   [PartCode]_v[Version].[ext]  (e.g., 176799901_v1.xlsx, 176799901_v2.pdf)
 *   [PartCode].[ext]             (default to v1)
 *
 * Automatically marks the highest version as 'is_latest' and default.
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
                // Find matching files starting with part code
                $pattern = $docFolder . DIRECTORY_SEPARATOR . $cleanPartCode . '*.*';
                $foundFiles = glob($pattern);

                if (!empty($foundFiles)) {
                    foreach ($foundFiles as $filePath) {
                        $filename = basename($filePath);
                        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

                        // Allowed formats: pdf, xlsx, xls
                        if (!in_array($ext, ['pdf', 'xlsx', 'xls', 'png', 'jpg', 'jpeg'])) {
                            continue;
                        }

                        // Determine version number
                        // Pattern: PartCode_v2.xlsx or PartCode-v2.xlsx or PartCode_rev2.xlsx
                        $versionNum = 1;
                        if (preg_match('/[_-]v(?:er)?([0-9]+)/i', $filename, $mVer)) {
                            $versionNum = (int)$mVer[1];
                        } elseif (preg_match('/[_-]rev([0-9]+)/i', $filename, $mRev)) {
                            $versionNum = (int)$mRev[1];
                        }

                        $relativeRelPath = 'uploads/documents/' . $folderName . '/' . $filename;

                        $versions[] = [
                            'version' => $versionNum,
                            'version_label' => 'v' . $versionNum,
                            'filename' => $filename,
                            'ext' => $ext,
                            'format' => in_array($ext, ['xlsx', 'xls']) ? 'excel' : ($ext === 'pdf' ? 'pdf' : 'image'),
                            'file_path' => $relativeRelPath,
                            'file_size' => filesize($filePath),
                            'modified_at' => filemtime($filePath),
                        ];
                    }
                }
            }

            // Sort versions descending: latest version first (v3, v2, v1)
            usort($versions, function ($a, $b) {
                if ($a['version'] === $b['version']) {
                    return $b['modified_at'] - $a['modified_at'];
                }
                return $b['version'] - $a['version'];
            });

            // Mark latest
            if (!empty($versions)) {
                foreach ($versions as $idx => &$v) {
                    $v['is_latest'] = ($idx === 0);
                    $v['version_label_full'] = $v['version_label'] . ($v['is_latest'] ? ' (Terbaru)' : '') . ' - ' . strtoupper($v['ext']);
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
