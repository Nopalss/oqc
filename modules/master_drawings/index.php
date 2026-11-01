<?php
$breadcrumbCategory = "MASTER DATA";
$pageTitle = "Master Data Drawing & Dokumen Mutu";
$pageSubtitle = "Kelola berkas acuan gambar teknik 2D/3D serta dokumen mutu (PI, Q-Point, PAC, Standar Packing) per Part Code";

require_once __DIR__ . '/../../layouts/header.php';
require_once __DIR__ . '/../../layouts/sidebar.php';
require_once __DIR__ . '/../../config/document_helper.php';

require_menu_access('master_drawings');

$pdo = getDB();
$csrfToken = generate_csrf_token();
$drawings = [];
$search = sanitize($_GET['search'] ?? '');
$status_filter = sanitize($_GET['status_filter'] ?? 'all');
$valid_filters = [
    'all', 'complete_all', 'incomplete_all', 'empty_all', 
    'has_2d', 'has_3d', 'no_2d', 'no_3d', 
    'no_pi', 'no_qp', 'no_pac', 'no_pack', 'no_mi',
    'no_drawing', 'has_drawing', 'complete'
];
if (!in_array($status_filter, $valid_filters)) {
    $status_filter = 'all';
}

// Dynamic Limit (Default: 10 rows per page)
$limit = filter_input(INPUT_GET, 'limit', FILTER_VALIDATE_INT) ?: 10;
if (!in_array($limit, [5, 10, 25, 50, 100])) {
    $limit = 10;
}

$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?: 1;
if ($page < 1) $page = 1;

$totalItems = 0;
$totalPages = 1;
$offset = 0;

// Inisialisasi KPI Metrics Global (Dihitung dari semua part hasil pencarian)
$kpi = [
    'total'          => 0,
    'complete_all'   => 0,
    'incomplete_all' => 0,
    'has_2d'         => 0,
    'has_3d'         => 0,
    'no_pi'          => 0,
    'no_qp'          => 0,
    'no_pac'         => 0,
    'no_pack'        => 0,
    'no_mi'          => 0,
];

if ($pdo) {
    try {
        // 1. Fast Indexing of Physical Drawing Folders (Single Pass)
        $rootAppDir = realpath(__DIR__ . '/../..');
        $baseDrawingsDir = $rootAppDir . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'drawings';
        $basePartsDir = $rootAppDir . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'parts';
        $diskFilesIndex = [];

        if (is_dir($baseDrawingsDir)) {
            $dirs = glob($baseDrawingsDir . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR);
            if ($dirs) {
                foreach ($dirs as $mDir) {
                    $bName = strtolower(basename($mDir));
                    if ($bName === '2d' || $bName === '3d') continue;
                    $pDirs = glob($mDir . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR);
                    if ($pDirs) {
                        foreach ($pDirs as $pd) {
                            $folderName = basename($pd);
                            $parts = preg_split('/[\s_]+/', $folderName);
                            $pCode = $parts[0] ?? '';
                            if (!$pCode) continue;

                            $pdfFiles = glob($pd . DIRECTORY_SEPARATOR . '*.pdf');
                            $stpFiles = glob($pd . DIRECTORY_SEPARATOR . '*.{stp,step}', GLOB_BRACE);

                            $rel2d = !empty($pdfFiles) ? str_replace([$rootAppDir . DIRECTORY_SEPARATOR, '\\'], ['', '/'], $pdfFiles[0]) : null;
                            $rel3d = !empty($stpFiles) ? str_replace([$rootAppDir . DIRECTORY_SEPARATOR, '\\'], ['', '/'], $stpFiles[0]) : null;

                            $diskFilesIndex[$pCode] = [
                                'path_2d' => $rel2d,
                                'path_3d' => $rel3d,
                                'has_2d'  => !empty($rel2d),
                                'has_3d'  => !empty($rel3d)
                            ];
                        }
                    }
                }
            }
        }

        // 1b. Fast Indexing of New Unified Parts Folders (uploads/parts/{PartCode}/)
        $partsUnifiedDocsIndex = [];
        if (is_dir($basePartsDir)) {
            $partDirs = glob($basePartsDir . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR);
            if ($partDirs) {
                foreach ($partDirs as $pDir) {
                    $folderPartCode = strtoupper(basename($pDir));
                    $pdfFiles = glob($pDir . DIRECTORY_SEPARATOR . '*.pdf') ?: [];
                    $stpFiles = glob($pDir . DIRECTORY_SEPARATOR . '*.{stp,step}', GLOB_BRACE) ?: [];

                    $docFlags = [
                        'has_2d' => false,
                        'has_3d' => !empty($stpFiles),
                        'pi' => false,
                        'qpoint' => false,
                        'pac' => false,
                        'standar_packing' => false,
                        'mi' => false,
                        'file_count' => count($pdfFiles) + count($stpFiles)
                    ];

                    foreach ($pdfFiles as $pdfP) {
                        $fName = basename($pdfP);
                        $type = classify_part_file_type($fName);
                        if ($type === 'drawing_2d') {
                            $docFlags['has_2d'] = true;
                        } elseif (isset($docFlags[$type])) {
                            $docFlags[$type] = true;
                        }
                    }

                    $partsUnifiedDocsIndex[$folderPartCode] = $docFlags;
                }
            }
        }

        // 2. Fetch Parts matching Search Criteria
        $sql = "SELECT p.id as part_id, p.part_code, p.part_name, 
                       COALESCE(m.name, p.model, '') as model_name,
                       d.id as drawing_id, d.drawing_2d_path, d.drawing_3d_path, d.updated_at
                FROM master_parts p
                LEFT JOIN master_models m ON m.id = p.model_id
                LEFT JOIN master_drawings d ON p.id = d.part_id
                WHERE 1=1";
        $params = [];

        if (!empty($search)) {
            $sql .= " AND (p.part_code LIKE :search1 OR p.part_name LIKE :search2)";
            $params[':search1'] = '%' . $search . '%';
            $params[':search2'] = '%' . $search . '%';
        }

        $sql .= " ORDER BY p.part_code ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rawParts = $stmt->fetchAll();

        // 3. Fast In-Memory Map Resolution, KPI Metric Count & Apply Status Filter
        $filteredParts = [];
        $kpi['total'] = count($rawParts);

        foreach ($rawParts as $row) {
            $pCode = $row['part_code'];
            $pCodeUpper = strtoupper(trim((string)$pCode));
            $disk = $diskFilesIndex[$pCode] ?? null;
            $unified = $partsUnifiedDocsIndex[$pCodeUpper] ?? null;

            $path2D = $disk['path_2d'] ?? null;
            if (!$path2D && !empty($row['drawing_2d_path']) && file_exists($rootAppDir . DIRECTORY_SEPARATOR . $row['drawing_2d_path'])) {
                $path2D = $row['drawing_2d_path'];
            }
            if (!$path2D && !empty($unified['has_2d'])) {
                $path2D = 'uploads/parts/' . $pCode . '/';
            }

            $path3D = $disk['path_3d'] ?? null;
            if (!$path3D && !empty($row['drawing_3d_path']) && file_exists($rootAppDir . DIRECTORY_SEPARATOR . $row['drawing_3d_path'])) {
                $path3D = $row['drawing_3d_path'];
            }
            if (!$path3D && !empty($unified['has_3d'])) {
                $path3D = 'uploads/parts/' . $pCode . '/';
            }

            $has2D = !empty($path2D);
            $has3D = !empty($path3D);
            $hasDrawing = $has2D || $has3D;

            $hasPi   = !empty($unified['pi']);
            $hasQp   = !empty($unified['qpoint']);
            $hasPac  = !empty($unified['pac']);
            $hasPack = !empty($unified['standar_packing']);
            $hasMi   = !empty($unified['mi']);

            $totalDocsCount = ($has2D ? 1 : 0) + ($has3D ? 1 : 0) + ($hasPi ? 1 : 0) + ($hasQp ? 1 : 0) + ($hasPac ? 1 : 0) + ($hasPack ? 1 : 0) + ($hasMi ? 1 : 0);
            $isCompleteAll = ($totalDocsCount === 7);
            $isEmptyAll = ($totalDocsCount === 0);

            // Akumulasi Angka KPI Global (In-Memory Single Pass)
            if ($isCompleteAll) {
                $kpi['complete_all']++;
            } else {
                $kpi['incomplete_all']++;
            }
            if ($has2D) $kpi['has_2d']++;
            if ($has3D) $kpi['has_3d']++;
            if (!$hasPi) $kpi['no_pi']++;
            if (!$hasQp) $kpi['no_qp']++;
            if (!$hasPac) $kpi['no_pac']++;
            if (!$hasPack) $kpi['no_pack']++;
            if (!$hasMi) $kpi['no_mi']++;

            // Evaluasi Filter Status
            $matchesFilter = true;
            switch ($status_filter) {
                case 'complete_all':
                    $matchesFilter = $isCompleteAll;
                    break;
                case 'incomplete_all':
                    $matchesFilter = !$isCompleteAll;
                    break;
                case 'empty_all':
                    $matchesFilter = $isEmptyAll;
                    break;
                case 'has_2d':
                    $matchesFilter = $has2D;
                    break;
                case 'no_2d':
                    $matchesFilter = !$has2D;
                    break;
                case 'has_3d':
                    $matchesFilter = $has3D;
                    break;
                case 'no_3d':
                    $matchesFilter = !$has3D;
                    break;
                case 'no_pi':
                    $matchesFilter = !$hasPi;
                    break;
                case 'no_qp':
                    $matchesFilter = !$hasQp;
                    break;
                case 'no_pac':
                    $matchesFilter = !$hasPac;
                    break;
                case 'no_pack':
                    $matchesFilter = !$hasPack;
                    break;
                case 'no_mi':
                    $matchesFilter = !$hasMi;
                    break;
                case 'has_drawing':
                    $matchesFilter = $hasDrawing;
                    break;
                case 'no_drawing':
                    $matchesFilter = !$hasDrawing;
                    break;
                case 'complete':
                    $matchesFilter = ($has2D && $has3D);
                    break;
                case 'all':
                default:
                    $matchesFilter = true;
                    break;
            }

            if (!$matchesFilter) {
                continue;
            }

            $row['resolved_2d_path'] = $path2D;
            $row['resolved_3d_path'] = $path3D;
            $row['has_2d'] = $has2D;
            $row['has_3d'] = $has3D;
            $row['has_drawing'] = $hasDrawing;
            $row['qc_docs'] = [
                'pi'              => ['has_document' => $hasPi],
                'qpoint'          => ['has_document' => $hasQp],
                'pac'             => ['has_document' => $hasPac],
                'standar_packing' => ['has_document' => $hasPack],
                'mi'              => ['has_document' => $hasMi]
            ];
            $row['total_docs_count'] = $totalDocsCount;
            $row['is_complete_all'] = $isCompleteAll;

            $filteredParts[] = $row;
        }

        // 4. Pagination Calculation
        $totalItems = count($filteredParts);
        $totalPages = ceil($totalItems / $limit);
        if ($totalPages < 1) $totalPages = 1;
        if ($page > $totalPages) $page = $totalPages;
        $offset = ($page - 1) * $limit;

        $drawings = array_slice($filteredParts, $offset, $limit);
    } catch (PDOException $e) {
        $drawings = [];
        $totalItems = 0;
        $totalPages = 1;
    }
}
?>

<div id="main-content-wrapper" class="flex-1 md:pl-64 flex flex-col min-h-screen transition-all duration-300">
    
    <?php require_once __DIR__ . '/../../layouts/navbar.php'; ?>

    <main class="flex-1 p-3 md:p-5 space-y-4">
        
        <?= render_flash() ?>

        <?php
        // Helper fungsi pembuat URL filter KPI yang mempertahankan search & limit
        $buildKpiUrl = function($targetFilter) use ($search, $limit, $status_filter) {
            $nextFilter = ($status_filter === $targetFilter) ? 'all' : $targetFilter;
            $params = ['status_filter' => $nextFilter];
            if (!empty($search)) $params['search'] = $search;
            if ($limit != 10) $params['limit'] = $limit;
            return base_url('modules/master_drawings/index.php?' . http_build_query($params));
        };
        ?>

        <!-- KPI Cards Baris 1: Status Utama & Gambar Teknik (Sejejer 5 Card) -->
        <div class="space-y-1.5">
            <div class="flex items-center justify-between px-0.5">
                <span class="text-[11px] font-bold text-slate-500 uppercase tracking-wider">Status Berkas &amp; Gambar Teknik</span>
                <span class="text-[11px] text-slate-400 font-medium">Klik card untuk memfilter</span>
            </div>
            <div style="display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 10px;">
                
                <!-- Card 1: Total Part (Slate/Zinc Themed) -->
                <a href="<?= $buildKpiUrl('all') ?>" 
                   style="background: #f8fafc; border-color: <?= ($status_filter === 'all') ? '#3b82f6' : '#e2e8f0' ?>; <?= ($status_filter === 'all') ? 'box-shadow: 0 0 0 2px rgba(59,130,246,0.3);' : '' ?>"
                   class="card p-3 rounded-xl border transition-all text-left block relative overflow-hidden group hover:shadow-xs">
                    <div class="flex items-center justify-between">
                        <span class="text-[10.5px] font-bold uppercase tracking-wider <?= ($status_filter === 'all') ? 'text-blue-800' : 'text-slate-600' ?>">Total Part</span>
                        <?php if ($status_filter === 'all'): ?>
                            <span class="px-1.5 py-0.2 rounded text-[9px] font-extrabold bg-blue-600 text-white">Semua</span>
                        <?php endif; ?>
                    </div>
                    <div class="mt-1 flex items-baseline justify-between">
                        <span class="text-xl font-extrabold text-slate-900"><?= number_format($kpi['total']) ?></span>
                        <span class="text-[10px] text-slate-400 font-medium">Master Part</span>
                    </div>
                </a>

                <!-- Card 2: Part Lengkap (Emerald/Hijau Khas) -->
                <a href="<?= $buildKpiUrl('complete_all') ?>" 
                   style="background: #ecfdf5; border-color: <?= ($status_filter === 'complete_all') ? '#10b981' : '#a7f3d0' ?>; <?= ($status_filter === 'complete_all') ? 'box-shadow: 0 0 0 2px rgba(16,185,129,0.35);' : '' ?>"
                   class="card p-3 rounded-xl border transition-all text-left block relative overflow-hidden group hover:shadow-xs">
                    <div class="flex items-center justify-between">
                        <span class="text-[10.5px] font-bold uppercase tracking-wider text-emerald-800">Part Lengkap</span>
                        <?php if ($status_filter === 'complete_all'): ?>
                            <span class="px-1.5 py-0.2 rounded text-[9px] font-extrabold bg-emerald-600 text-white">Aktif</span>
                        <?php endif; ?>
                    </div>
                    <div class="mt-1 flex items-baseline justify-between">
                        <span class="text-xl font-extrabold text-emerald-700"><?= number_format($kpi['complete_all']) ?></span>
                        <span class="text-[10px] text-emerald-600 font-semibold">6/6 Dokumen</span>
                    </div>
                </a>

                <!-- Card 3: Belum Lengkap (Rose/Merah Khas) -->
                <a href="<?= $buildKpiUrl('incomplete_all') ?>" 
                   style="background: #fff1f2; border-color: <?= ($status_filter === 'incomplete_all') ? '#f43f5e' : '#fecdd3' ?>; <?= ($status_filter === 'incomplete_all') ? 'box-shadow: 0 0 0 2px rgba(244,63,94,0.35);' : '' ?>"
                   class="card p-3 rounded-xl border transition-all text-left block relative overflow-hidden group hover:shadow-xs">
                    <div class="flex items-center justify-between">
                        <span class="text-[10.5px] font-bold uppercase tracking-wider text-rose-800">Belum Lengkap</span>
                        <?php if ($status_filter === 'incomplete_all'): ?>
                            <span class="px-1.5 py-0.2 rounded text-[9px] font-extrabold bg-rose-600 text-white">Aktif</span>
                        <?php endif; ?>
                    </div>
                    <div class="mt-1 flex items-baseline justify-between">
                        <span class="text-xl font-extrabold text-rose-700"><?= number_format($kpi['incomplete_all']) ?></span>
                        <span class="text-[10px] text-rose-600 font-semibold">Ada Kurang</span>
                    </div>
                </a>

                <!-- Card 4: Sudah Ada 2D (Sky/Blue Khas) -->
                <a href="<?= $buildKpiUrl('has_2d') ?>" 
                   style="background: #eff6ff; border-color: <?= ($status_filter === 'has_2d') ? '#2563eb' : '#bfdbfe' ?>; <?= ($status_filter === 'has_2d') ? 'box-shadow: 0 0 0 2px rgba(37,99,235,0.35);' : '' ?>"
                   class="card p-3 rounded-xl border transition-all text-left block relative overflow-hidden group hover:shadow-xs">
                    <div class="flex items-center justify-between">
                        <span class="text-[10.5px] font-bold uppercase tracking-wider text-blue-800">Sudah Ada 2D</span>
                        <?php if ($status_filter === 'has_2d'): ?>
                            <span class="px-1.5 py-0.2 rounded text-[9px] font-extrabold bg-blue-600 text-white">Aktif</span>
                        <?php endif; ?>
                    </div>
                    <div class="mt-1 flex items-baseline justify-between">
                        <span class="text-xl font-extrabold text-blue-700"><?= number_format($kpi['has_2d']) ?></span>
                        <span class="text-[10px] text-blue-600 font-semibold">Gambar PDF</span>
                    </div>
                </a>

                <!-- Card 5: Sudah Ada 3D (Indigo/Solid 3D Khas) -->
                <a href="<?= $buildKpiUrl('has_3d') ?>" 
                   style="background: #eef2ff; border-color: <?= ($status_filter === 'has_3d') ? '#4f46e5' : '#c7d2fe' ?>; <?= ($status_filter === 'has_3d') ? 'box-shadow: 0 0 0 2px rgba(79,70,229,0.35);' : '' ?>"
                   class="card p-3 rounded-xl border transition-all text-left block relative overflow-hidden group hover:shadow-xs">
                    <div class="flex items-center justify-between">
                        <span class="text-[10.5px] font-bold uppercase tracking-wider text-indigo-800">Sudah Ada 3D</span>
                        <?php if ($status_filter === 'has_3d'): ?>
                            <span class="px-1.5 py-0.2 rounded text-[9px] font-extrabold bg-indigo-600 text-white">Aktif</span>
                        <?php endif; ?>
                    </div>
                    <div class="mt-1 flex items-baseline justify-between">
                        <span class="text-xl font-extrabold text-indigo-700"><?= number_format($kpi['has_3d']) ?></span>
                        <span class="text-[10px] text-indigo-600 font-semibold">Model STP</span>
                    </div>
                </a>

            </div>
        </div>

        <!-- KPI Cards Baris 2: Fokus Audit Dokumen Mutu yang Belum Ada (Sejejer 5 Card) -->
        <div class="space-y-1.5">
            <div class="flex items-center justify-between px-0.5">
                <span class="text-[11px] font-bold text-slate-500 uppercase tracking-wider">Audit Dokumen Mutu (Yang Belum Ada)</span>
                <span class="text-[11px] text-amber-600 font-semibold">Prioritas Pemenuhan Berkas</span>
            </div>
            <div style="display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 10px;">
                
                <!-- Card B1: Belum Ada PI (Indigo Tint Khas QC) -->
                <a href="<?= $buildKpiUrl('no_pi') ?>" 
                   style="background: #fdf4ff; border-color: <?= ($status_filter === 'no_pi') ? '#c026d3' : '#f5d0fe' ?>; <?= ($status_filter === 'no_pi') ? 'box-shadow: 0 0 0 2px rgba(192,38,211,0.35);' : '' ?>"
                   class="card p-3 rounded-xl border transition-all text-left block relative overflow-hidden group hover:shadow-xs">
                    <div class="flex items-center justify-between">
                        <span class="text-[10.5px] font-bold uppercase tracking-wider text-fuchsia-900">Belum Ada PI</span>
                        <?php if ($status_filter === 'no_pi'): ?>
                            <span class="px-1.5 py-0.2 rounded text-[9px] font-extrabold bg-fuchsia-600 text-white">Aktif</span>
                        <?php else: ?>
                            <span class="px-1.5 py-0.2 rounded text-[9px] font-bold bg-fuchsia-100 text-fuchsia-800">PI</span>
                        <?php endif; ?>
                    </div>
                    <div class="mt-1 flex items-baseline justify-between">
                        <span class="text-xl font-extrabold text-fuchsia-800"><?= number_format($kpi['no_pi']) ?></span>
                        <span class="text-[10px] text-fuchsia-700 font-semibold">Kosong PI</span>
                    </div>
                </a>

                <!-- Card B2: Belum Ada QP (Amber/Kuning Quality Point Khas) -->
                <a href="<?= $buildKpiUrl('no_qp') ?>" 
                   style="background: #fffbeb; border-color: <?= ($status_filter === 'no_qp') ? '#d97706' : '#fde68a' ?>; <?= ($status_filter === 'no_qp') ? 'box-shadow: 0 0 0 2px rgba(217,119,6,0.35);' : '' ?>"
                   class="card p-3 rounded-xl border transition-all text-left block relative overflow-hidden group hover:shadow-xs">
                    <div class="flex items-center justify-between">
                        <span class="text-[10.5px] font-bold uppercase tracking-wider text-amber-900">Belum Ada QP</span>
                        <?php if ($status_filter === 'no_qp'): ?>
                            <span class="px-1.5 py-0.2 rounded text-[9px] font-extrabold bg-amber-600 text-white">Aktif</span>
                        <?php else: ?>
                            <span class="px-1.5 py-0.2 rounded text-[9px] font-bold bg-amber-100 text-amber-800">Q-Point</span>
                        <?php endif; ?>
                    </div>
                    <div class="mt-1 flex items-baseline justify-between">
                        <span class="text-xl font-extrabold text-amber-800"><?= number_format($kpi['no_qp']) ?></span>
                        <span class="text-[10px] text-amber-700 font-semibold">Kosong QP</span>
                    </div>
                </a>

                <!-- Card B3: Belum Ada PAC (Rose/Approval Khas) -->
                <a href="<?= $buildKpiUrl('no_pac') ?>" 
                   style="background: #fff1f2; border-color: <?= ($status_filter === 'no_pac') ? '#e11d48' : '#fecdd3' ?>; <?= ($status_filter === 'no_pac') ? 'box-shadow: 0 0 0 2px rgba(225,29,72,0.35);' : '' ?>"
                   class="card p-3 rounded-xl border transition-all text-left block relative overflow-hidden group hover:shadow-xs">
                    <div class="flex items-center justify-between">
                        <span class="text-[10.5px] font-bold uppercase tracking-wider text-rose-900">Belum Ada PAC</span>
                        <?php if ($status_filter === 'no_pac'): ?>
                            <span class="px-1.5 py-0.2 rounded text-[9px] font-extrabold bg-rose-600 text-white">Aktif</span>
                        <?php else: ?>
                            <span class="px-1.5 py-0.2 rounded text-[9px] font-bold bg-rose-100 text-rose-800">PAC</span>
                        <?php endif; ?>
                    </div>
                    <div class="mt-1 flex items-baseline justify-between">
                        <span class="text-xl font-extrabold text-rose-800"><?= number_format($kpi['no_pac']) ?></span>
                        <span class="text-[10px] text-rose-700 font-semibold">Kosong PAC</span>
                    </div>
                </a>

                <!-- Card B4: Belum Ada PACK (Orange Packing Khas) -->
                <a href="<?= $buildKpiUrl('no_pack') ?>" 
                   style="background: #fff7ed; border-color: <?= ($status_filter === 'no_pack') ? '#ea580c' : '#fed7aa' ?>; <?= ($status_filter === 'no_pack') ? 'box-shadow: 0 0 0 2px rgba(234,88,12,0.35);' : '' ?>"
                   class="card p-3 rounded-xl border transition-all text-left block relative overflow-hidden group hover:shadow-xs">
                    <div class="flex items-center justify-between">
                        <span class="text-[10.5px] font-bold uppercase tracking-wider text-orange-950">Belum Ada PACK</span>
                        <?php if ($status_filter === 'no_pack'): ?>
                            <span class="px-1.5 py-0.2 rounded text-[9px] font-extrabold bg-orange-600 text-white">Aktif</span>
                        <?php else: ?>
                            <span class="px-1.5 py-0.2 rounded text-[9px] font-bold bg-orange-100 text-orange-800">Packing</span>
                        <?php endif; ?>
                    </div>
                    <div class="mt-1 flex items-baseline justify-between">
                        <span class="text-xl font-extrabold text-orange-800"><?= number_format($kpi['no_pack']) ?></span>
                        <span class="text-[10px] text-orange-700 font-semibold">Kosong Packing</span>
                    </div>
                </a>

                <!-- Card B5: Belum Ada MI (Cyan Monitoring Model Khas) -->
                <a href="<?= $buildKpiUrl('no_mi') ?>" 
                   style="background: #ecfeff; border-color: <?= ($status_filter === 'no_mi') ? '#0891b2' : '#a5f3fc' ?>; <?= ($status_filter === 'no_mi') ? 'box-shadow: 0 0 0 2px rgba(8,145,178,0.35);' : '' ?>"
                   class="card p-3 rounded-xl border transition-all text-left block relative overflow-hidden group hover:shadow-xs">
                    <div class="flex items-center justify-between">
                        <span class="text-[10.5px] font-bold uppercase tracking-wider text-cyan-950">Belum Ada MI</span>
                        <?php if ($status_filter === 'no_mi'): ?>
                            <span class="px-1.5 py-0.2 rounded text-[9px] font-extrabold bg-cyan-600 text-white">Aktif</span>
                        <?php else: ?>
                            <span class="px-1.5 py-0.2 rounded text-[9px] font-bold bg-cyan-100 text-cyan-800">Monitoring</span>
                        <?php endif; ?>
                    </div>
                    <div class="mt-1 flex items-baseline justify-between">
                        <span class="text-xl font-extrabold text-cyan-800"><?= number_format($kpi['no_mi']) ?></span>
                        <span class="text-[10px] text-cyan-700 font-semibold">Kosong MI</span>
                    </div>
                </a>

            </div>
        </div>

        <!-- Ultra Compact 1-Line Filter Bar Card -->
        <div class="card p-2.5 bg-white border border-slate-200/80 rounded-xl shadow-xs">
            <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px; flex-wrap: wrap;">
                
                <form action="" method="GET" style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap; flex: 1;">
                    <!-- Search Input (Precise Icon Inside Box) -->
                    <div style="position: relative; width: 220px; max-width: 100%;">
                        <svg style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%); width: 14px; height: 14px; color: #94a3b8; pointer-events: none;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                        </svg>
                        <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" 
                                placeholder="Cari Part Code / Nama..." class="form-input py-1 text-xs" style="padding-left: 30px; width: 100%;">
                    </div>

                    <!-- Formal Status Drawing Filter Dropdown -->
                    <select name="status_filter" onchange="this.form.submit()" class="form-input py-1 text-xs font-semibold text-slate-700" style="width: auto; min-width: 220px;">
                        <option value="all" <?= ($status_filter === 'all') ? 'selected' : '' ?>>Semua Status Drawing &amp; Dokumen</option>
                        <option value="complete_all" <?= ($status_filter === 'complete_all') ? 'selected' : '' ?>>Dokumen Lengkap 100% (6/6)</option>
                        <option value="incomplete_all" <?= ($status_filter === 'incomplete_all') ? 'selected' : '' ?>>Dokumen Belum Lengkap (Kurang)</option>
                        <option value="empty_all" <?= ($status_filter === 'empty_all') ? 'selected' : '' ?>>Berkas Kosong Total (0/6 Dokumen)</option>
                        <option value="has_2d" <?= ($status_filter === 'has_2d') ? 'selected' : '' ?>>Sudah Ada Gambar 2D (PDF)</option>
                        <option value="no_2d" <?= ($status_filter === 'no_2d') ? 'selected' : '' ?>>Belum Ada Gambar 2D (PDF)</option>
                        <option value="has_3d" <?= ($status_filter === 'has_3d') ? 'selected' : '' ?>>Sudah Ada Gambar 3D (.STP)</option>
                        <option value="no_3d" <?= ($status_filter === 'no_3d') ? 'selected' : '' ?>>Belum Ada Gambar 3D (.STP)</option>
                        <option value="no_pi" <?= ($status_filter === 'no_pi') ? 'selected' : '' ?>>Belum Ada Dokumen PI</option>
                        <option value="no_qp" <?= ($status_filter === 'no_qp') ? 'selected' : '' ?>>Belum Ada Dokumen Q-Point</option>
                        <option value="no_pac" <?= ($status_filter === 'no_pac') ? 'selected' : '' ?>>Belum Ada Dokumen PAC</option>
                        <option value="no_pack" <?= ($status_filter === 'no_pack') ? 'selected' : '' ?>>Belum Ada Dokumen Packing</option>
                    </select>

                    <!-- Limit Selector Dropdown -->
                    <select name="limit" onchange="this.form.submit()" class="form-input py-1 text-xs font-semibold text-slate-700" style="width: 85px;">
                        <option value="5" <?= ($limit == 5) ? 'selected' : '' ?>>5 / hal</option>
                        <option value="10" <?= ($limit == 10) ? 'selected' : '' ?>>10 / hal</option>
                        <option value="25" <?= ($limit == 25) ? 'selected' : '' ?>>25 / hal</option>
                        <option value="50" <?= ($limit == 50) ? 'selected' : '' ?>>50 / hal</option>
                        <option value="100" <?= ($limit == 100) ? 'selected' : '' ?>>100 / hal</option>
                    </select>

                    <button type="submit" class="btn-secondary py-1 px-2.5 text-xs font-bold">Cari</button>
                    <?php if (!empty($search) || $limit != 10 || ($status_filter !== 'all' && !empty($status_filter))): ?>
                        <a href="<?= base_url('modules/master_drawings/index.php') ?>" class="text-[11px] text-rose-600 font-semibold hover:underline">Reset</a>
                    <?php endif; ?>
                </form>

                <div class="flex items-center gap-3">
                    <div class="text-xs text-slate-500 font-medium whitespace-nowrap">
                        Hasil Filter: <span class="font-bold text-slate-800"><?= $totalItems ?></span> dari <?= $kpi['total'] ?> Part
                    </div>

                    <a href="<?= base_url('modules/master_drawings/import_zip.php') ?>" class="btn-primary py-1 px-3 text-xs font-bold inline-flex items-center gap-1.5 rounded-lg shadow-xs hover:shadow-sm">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
                        <span>Import Berkas (ZIP)</span>
                    </a>
                </div>

            </div>
        </div>

        <!-- Table Card -->
        <div class="card p-0 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs text-slate-700">
                    <thead class="bg-slate-50 text-slate-600 font-bold border-b border-slate-200/80 uppercase tracking-wider text-[10px]">
                        <tr>
                            <th class="px-4 py-3">No</th>
                            <th class="px-4 py-3">Part Code</th>
                            <th class="px-4 py-3">Part Name</th>
                            <th class="px-4 py-3 text-center">Gambar 2D</th>
                            <th class="px-4 py-3 text-center">Gambar 3D</th>
                            <th class="px-4 py-3 text-center">Dokumen Mutu</th>
                            <th class="px-4 py-3">Update Terakhir</th>
                            <th class="px-4 py-3 text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php if (empty($drawings)): ?>
                            <tr>
                                <td colspan="8" class="px-4 py-8 text-center text-slate-400">
                                    Belum ada data Master Part & Drawing.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($drawings as $index => $row): 
                                $path2D = $row['resolved_2d_path'] ?? null;
                                $path3D = $row['resolved_3d_path'] ?? null;
                                $has2D = !empty($row['has_2d']);
                                $has3D = !empty($row['has_3d']);
                                $hasDrawing = !empty($row['has_drawing']);
                            ?>
                                <tr class="hover:bg-slate-50/80 transition-colors">
                                    <td class="px-4 py-3 font-semibold text-slate-400"><?= $offset + $index + 1 ?></td>
                                    <td class="px-4 py-3 font-extrabold text-blue-700 font-mono whitespace-nowrap">
                                        <?= htmlspecialchars($row['part_code']) ?>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="font-semibold text-slate-800 block"><?= htmlspecialchars($row['part_name']) ?></span>
                                        <?php if (!empty($row['model_name'])): ?>
                                            <span class="text-[10px] text-slate-400 font-medium block mt-0.5">Model: <?= htmlspecialchars($row['model_name']) ?></span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- 2D PDF Status & Quick Download -->
                                    <td class="px-4 py-3 text-center whitespace-nowrap">
                                        <?php if ($has2D): ?>
                                            <div class="inline-flex items-center gap-1.5">
                                                <span class="badge badge-success">2D Ready</span>
                                                <a href="<?= base_url($path2D) ?>" download class="px-2 py-0.5 bg-blue-50 hover:bg-blue-100 text-blue-700 border border-blue-200 text-[10px] font-bold rounded transition-colors" title="Download Gambar 2D PDF">
                                                    Unduh PDF
                                                </a>
                                            </div>
                                        <?php else: ?>
                                            <span class="badge badge-secondary">Belum Ada</span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- 3D STP Status & Quick Download -->
                                    <td class="px-4 py-3 text-center whitespace-nowrap">
                                        <?php if ($has3D): ?>
                                            <div class="inline-flex items-center gap-1.5">
                                                <span class="badge badge-success">3D Ready</span>
                                                <a href="<?= base_url($path3D) ?>" download class="px-2 py-0.5 bg-emerald-50 hover:bg-emerald-100 text-emerald-800 border border-emerald-300 text-[10px] font-bold rounded transition-colors" title="Download Gambar 3D STP">
                                                    Unduh STP
                                                </a>
                                            </div>
                                        <?php else: ?>
                                            <span class="badge badge-secondary">Belum Ada</span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- QC Documents Status Badges -->
                                    <td class="px-4 py-3 text-center whitespace-nowrap">
                                        <?php
                                        $qcd = $row['qc_docs'] ?? [];
                                        $hasPi = !empty($qcd['pi']['has_document']);
                                        $hasQp = !empty($qcd['qpoint']['has_document']);
                                        $hasPac = !empty($qcd['pac']['has_document']);
                                        $hasPack = !empty($qcd['standar_packing']['has_document']);
                                        $hasMi = !empty($qcd['mi']['has_document']);
                                        ?>
                                        <div class="inline-flex items-center gap-1">
                                            <span class="px-1.5 py-0.5 rounded text-[9.5px] font-extrabold <?= $hasPi ? 'bg-indigo-100 text-indigo-800 border border-indigo-200' : 'bg-slate-100 text-slate-400' ?>" title="<?= $hasPi ? 'PI Tersedia' : 'PI Belum Ada' ?>">PI</span>
                                            <span class="px-1.5 py-0.5 rounded text-[9.5px] font-extrabold <?= $hasQp ? 'bg-amber-100 text-amber-800 border border-amber-200' : 'bg-slate-100 text-slate-400' ?>" title="<?= $hasQp ? 'Q-Point Tersedia' : 'Q-Point Belum Ada' ?>">QP</span>
                                            <span class="px-1.5 py-0.5 rounded text-[9.5px] font-extrabold <?= $hasPac ? 'bg-emerald-100 text-emerald-800 border border-emerald-200' : 'bg-slate-100 text-slate-400' ?>" title="<?= $hasPac ? 'PAC Tersedia' : 'PAC Belum Ada' ?>">PAC</span>
                                            <span class="px-1.5 py-0.5 rounded text-[9.5px] font-extrabold <?= $hasPack ? 'bg-orange-100 text-orange-800 border border-orange-200' : 'bg-slate-100 text-slate-400' ?>" title="<?= $hasPack ? 'Standar Packing Tersedia' : 'Standar Packing Belum Ada' ?>">PACK</span>
                                            <span class="px-1.5 py-0.5 rounded text-[9.5px] font-extrabold <?= $hasMi ? 'bg-cyan-100 text-cyan-800 border border-cyan-200' : 'bg-slate-100 text-slate-400' ?>" title="<?= $hasMi ? 'Monitoring Model Tersedia' : 'Monitoring Model Belum Ada' ?>">MI</span>
                                        </div>
                                    </td>

                                    <td class="px-4 py-3 text-[11px] text-slate-500 whitespace-nowrap">
                                        <?= format_date($row['updated_at'] ?? null) ?>
                                    </td>

                                    <td class="px-4 py-3 text-right whitespace-nowrap">
                                        <?php
                                        // Tombol 'Lihat' dan 'Update File' aktif jika ada berkas apa pun (drawing 2D/3D atau dokumen mutu PI, QP, PAC, PACK, MI)
                                        $hasAnyFile = $hasDrawing || $hasPi || $hasQp || $hasPac || $hasPack || $hasMi || !empty($row['drawing_id']);
                                        ?>
                                        <div class="inline-flex items-center justify-end gap-1 flex-wrap">
                                            <!-- View Detail / Viewer (Muncul jika sudah ada berkas apa pun diunggah) -->
                                            <?php if ($hasAnyFile): ?>
                                                <a href="<?= base_url('modules/master_drawings/detail.php?part_id=' . $row['part_id']) ?>" 
                                                   class="py-1 px-2.5 text-[11px] font-bold rounded-lg bg-blue-600 hover:bg-blue-700 text-white shadow-xs transition-colors inline-flex items-center gap-1" title="Lihat Pratinjau Drawing &amp; Dokumen">
                                                    <span>Lihat</span>
                                                </a>
                                            <?php endif; ?>

                                            <!-- Upload / Update File -->
                                            <?php if ($hasAnyFile): ?>
                                                <a href="<?= base_url('modules/master_drawings/upload.php?part_id=' . $row['part_id']) ?>" 
                                                   class="py-1 px-2.5 text-[11px] font-bold rounded-lg bg-amber-50 hover:bg-amber-100 text-amber-800 border border-amber-300 transition-colors inline-flex items-center gap-1 shadow-2xs" title="Perbarui Berkas Drawing / Dokumen Mutu">
                                                    <span>Update File</span>
                                                </a>
                                            <?php else: ?>
                                                <a href="<?= base_url('modules/master_drawings/upload.php?part_id=' . $row['part_id']) ?>" 
                                                   class="py-1 px-2.5 text-[11px] font-bold rounded-lg bg-emerald-50 hover:bg-emerald-100 text-emerald-800 border border-emerald-300 transition-colors inline-flex items-center gap-1 shadow-2xs" title="Upload Berkas Baru">
                                                    <span>Upload</span>
                                                </a>
                                            <?php endif; ?>

                                            <!-- Delete Drawing & Documents (Secure POST with CSRF) -->
                                            <?php if ($hasAnyFile): ?>
                                                <button type="button" 
                                                        onclick="confirmPostDelete('<?= base_url('modules/master_drawings/delete.php') ?>', '<?= !empty($row['drawing_id']) ? $row['drawing_id'] : '' ?>', 'Drawing Part <?= htmlspecialchars($row['part_code'], ENT_QUOTES) ?>', '<?= $csrfToken ?>', { part_id: '<?= $row['part_id'] ?>' })"
                                                        class="p-1 rounded-lg bg-rose-50 hover:bg-rose-600 text-rose-600 hover:text-white border border-rose-200 transition-colors" title="Hapus Berkas &amp; Folder Dokumen Part">
                                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                                    </svg>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination Footer -->
            <?= render_pagination($page, $totalPages, $totalItems, $limit) ?>

        </div>

    </main>

<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>
