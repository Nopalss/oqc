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
if (!function_exists('classify_part_file_type')) {
    /**
     * Mengenali jenis dokumen berdasarkan nama file dan ekstensinya
     */
    function classify_part_file_type($filename) {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $nameOnly = strtolower(pathinfo($filename, PATHINFO_FILENAME));

        if ($ext === 'stp' || $ext === 'step') {
            return 'drawing_3d';
        }

        if ($ext === 'pdf') {
            // Cek kata kunci dokumen QC
            if (preg_match('/(^|[_\-\s])(pi|process[_\-\s]?inspection)($|[_\-\s0-9])/i', $nameOnly)) {
                return 'pi';
            }
            if (preg_match('/(^|[_\-\s])(qp|qpoint|q[_\-\s]?point|quality[_\-\s]?point)($|[_\-\s0-9])/i', $nameOnly)) {
                return 'qpoint';
            }
            if (preg_match('/(^|[_\-\s])(pac|part[_\-\s]?approval|certificate)($|[_\-\s0-9])/i', $nameOnly)) {
                return 'pac';
            }
            if (preg_match('/(^|[_\-\s])(pack|packing|standar[_\-\s]?pack)($|[_\-\s0-9])/i', $nameOnly)) {
                return 'standar_packing';
            }
            // Sisanya diklasifikasikan sebagai gambar kerja 2D
            return 'drawing_2d';
        }

        return 'unknown';
    }
}

if (!function_exists('extract_part_code_from_string')) {
    /**
     * Ekstrak Part Code dari nama folder atau file (misal: "1768125_COVER SUB-ASSY" -> "1768125", "1768125-00.pdf" -> "1768125")
     */
    function extract_part_code_from_string($str) {
        $clean = trim($str, "/\\ \t\n\r\0\x0B");
        if ($clean === '') return '';
        // Buang ekstensi jika ada
        $clean = pathinfo($clean, PATHINFO_FILENAME);
        // Coba tangkap alphanumeric dengan kemungkinan tanda minus di tengah (seperti kode part: 1768125 atau ABC-123)
        // Pemisah nama part biasanya spasi atau underscore ganda/tunggal diikuti teks
        if (preg_match('/^([a-zA-Z0-9]+(?:-[a-zA-Z0-9]+)?)/', $clean, $m)) {
            return $m[1];
        }
        return $clean;
    }
}

/**
 * Scan uploads/parts/{PartCode}/ atau uploads/documents/ untuk semua PDF dokumen QC.
 */
if (!function_exists('get_part_documents_summary')) {
    function get_part_documents_summary($partCode) {
        $cleanPartCode = trim((string)$partCode);
        $configs = get_document_types_config();
        $result = [];

        $rootAppDir = realpath(__DIR__ . '/..');
        $unifiedPartDir = $rootAppDir ? ($rootAppDir . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'parts' . DIRECTORY_SEPARATOR . $cleanPartCode) : null;

        // Tambahkan konfigurasi 2D dan 3D ke daftar tipe dokumen yang dipindai
        $allDocConfigs = array_merge([
            '2d' => [
                'code' => '2d',
                'label' => 'Gambar 2D',
                'full_label' => 'Gambar Kerja 2D (PDF)',
                'folder' => '',
            ],
            '3d' => [
                'code' => '3d',
                'label' => 'Tampilan 3D',
                'full_label' => 'Model Solid 3D (.STP/.STEP)',
                'folder' => '',
            ],
        ], $configs);

        // Ambil semua file di folder part (PDF, STP, STEP)
        $allPartFiles = [];
        if ($cleanPartCode !== '' && $unifiedPartDir && is_dir($unifiedPartDir)) {
            $rawFiles = scandir($unifiedPartDir) ?: [];
            foreach ($rawFiles as $rf) {
                if ($rf === '.' || $rf === '..') continue;
                $fPath = $unifiedPartDir . DIRECTORY_SEPARATOR . $rf;
                if (is_file($fPath)) {
                    $allPartFiles[] = $fPath;
                }
            }
        }

        foreach ($allDocConfigs as $docKey => $cfg) {
            $versions = [];
            $scannedFiles = [];

            if (!empty($allPartFiles)) {
                foreach ($allPartFiles as $upPath) {
                    $uName = basename($upPath);
                    $detectedType = classify_part_file_type($uName);
                    
                    // Normalisasi pemetaan 2d & 3d
                    $matchesThisType = false;
                    if ($docKey === '2d' && $detectedType === 'drawing_2d') {
                        $matchesThisType = true;
                    } elseif ($docKey === '3d' && $detectedType === 'drawing_3d') {
                        $matchesThisType = true;
                    } elseif ($detectedType === $docKey) {
                        $matchesThisType = true;
                    }

                    if ($matchesThisType) {
                        $scannedFiles[] = [
                            'path' => $upPath,
                            'is_unified' => true
                        ];
                    }
                }
            }

            if (!empty($scannedFiles)) {
                foreach ($scannedFiles as $fItem) {
                    $filePath = $fItem['path'];
                    $filename = basename($filePath);
                    $fileExt = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

                    // Parse nomor revisi
                    $versionOrder = 0;
                    $versionLabel = 'Revisi 00';

                    if (preg_match('/(?:[_\-\s](?:v|rev)?([0-9]+))\.[a-z0-9]+$/i', $filename, $mNum)) {
                        $versionOrder = (int)$mNum[1];
                        $versionLabel = 'Revisi ' . str_pad($versionOrder, 2, '0', STR_PAD_LEFT);
                    } elseif (preg_match('/(?:v|rev)([0-9]+)\.[a-z0-9]+$/i', $filename, $mNum2)) {
                        $versionOrder = (int)$mNum2[1];
                        $versionLabel = 'v' . $versionOrder;
                    }

                    $relativeRelPath = 'uploads/parts/' . $cleanPartCode . '/' . $filename;

                    $versions[] = [
                        'version' => $versionOrder,
                        'version_key' => $filename,
                        'version_label' => $versionLabel,
                        'filename' => $filename,
                        'ext' => $fileExt,
                        'format' => $fileExt,
                        'file_path' => $relativeRelPath,
                        'relative_url' => $relativeRelPath,
                        'full_url' => function_exists('base_url') ? base_url($relativeRelPath) : (defined('BASE_URL') ? (BASE_URL . '/' . $relativeRelPath) : $relativeRelPath),
                        'file_size' => @filesize($filePath) ?: 0,
                        'modified_at' => @filemtime($filePath) ?: 0,
                    ];
                }
            }

            // Urutkan versi terbaru di paling atas
            usort($versions, function ($a, $b) {
                if ($a['version'] === $b['version']) {
                    return $b['modified_at'] - $a['modified_at'];
                }
                return $b['version'] - $a['version'];
            });

            // Beri penanda is_latest
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

if (!function_exists('resolve_safe_document_upload_name')) {
    /**
     * Menghasilkan nama file yang aman, mendukung penamaan versi dari pengguna
     * dan mencegah penimpaan file lama (auto-increment versi jika nama sudah ada).
     *
     * @param string $partCode
     * @param string $docKey ('drawing_2d', 'drawing_3d', 'pi', 'qpoint', 'pac', 'standar_packing')
     * @param string $originalFilename Nama file asli saat di-upload user
     * @param string $unifiedPartDir Path folder uploads/parts/{PartCode}/
     * @return string Nama file tujuan yang aman
     */
    function resolve_safe_document_upload_name($partCode, $docKey, $originalFilename, $unifiedPartDir) {
        $ext = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
        $nameOnly = pathinfo($originalFilename, PATHINFO_FILENAME);

        // Standar prefix nama dasar jika tidak terdeteksi
        $basePrefixMap = [
            'drawing_2d'      => $partCode,
            'drawing_3d'      => $partCode,
            'pi'              => 'pi',
            'qpoint'          => 'qpoint',
            'pac'             => 'pac',
            'standar_packing' => 'packing'
        ];
        $defaultPrefix = $basePrefixMap[$docKey] ?? $docKey;

        // 1. Cek apakah user sudah memberikan penamaan versi di nama file (misal: pac_02, pi_01, 1768125-01)
        $userRev = null;
        if (preg_match('/(?:[_\-\s](?:v|rev)?([0-9]+))$/i', $nameOnly, $mNum)) {
            $userRev = (int)$mNum[1];
        } elseif (preg_match('/(?:v|rev)([0-9]+)$/i', $nameOnly, $mNum2)) {
            $userRev = (int)$mNum2[1];
        }

        // Tentukan kandidat nama file
        $sep = ($docKey === 'drawing_2d' || $docKey === 'drawing_3d') ? '-' : '_';
        
        if ($userRev !== null) {
            $candidateName = $defaultPrefix . $sep . str_pad($userRev, 2, '0', STR_PAD_LEFT) . '.' . $ext;
        } else {
            $candidateName = $defaultPrefix . $sep . '00.' . $ext;
        }

        $targetPath = $unifiedPartDir . DIRECTORY_SEPARATOR . $candidateName;

        // 2. Jika file dengan nama kandidat belum ada di server, pakai nama tersebut
        if (!file_exists($targetPath)) {
            return $candidateName;
        }

        // 3. Jika file sudah ada di server, lakukan auto-increment nomor revisi agar versi lama tidak tertimpa
        $existingFiles = glob($unifiedPartDir . DIRECTORY_SEPARATOR . '*.' . $ext) ?: [];
        $maxRev = ($userRev !== null) ? $userRev : 0;

        foreach ($existingFiles as $ef) {
            $efName = basename($ef);
            $efType = classify_part_file_type($efName);
            if ($efType === $docKey) {
                if (preg_match('/(?:[_\-\s](?:v|rev)?([0-9]+))\.' . preg_quote($ext, '/') . '$/i', $efName, $mE)) {
                    $rNum = (int)$mE[1];
                    if ($rNum > $maxRev) {
                        $maxRev = $rNum;
                    }
                }
            }
        }

        // Beri nomor versi berikutnya
        $nextRev = $maxRev + 1;
        return $defaultPrefix . $sep . str_pad($nextRev, 2, '0', STR_PAD_LEFT) . '.' . $ext;
    }
}

