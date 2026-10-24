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
if (!in_array($status_filter, ['all', 'no_drawing', 'no_2d', 'no_3d', 'has_drawing', 'complete'])) {
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

if ($pdo) {
    try {
        // 1. Fast Indexing of Physical Drawing Folders (Single Pass)
        $rootAppDir = realpath(__DIR__ . '/../..');
        $baseDrawingsDir = $rootAppDir . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'drawings';
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

        // 3. Fast In-Memory Map Resolution & Apply Status Filter (No recursive disk scans)
        $filteredParts = [];
        foreach ($rawParts as $row) {
            $pCode = $row['part_code'];
            $disk = $diskFilesIndex[$pCode] ?? null;

            $path2D = $disk['path_2d'] ?? null;
            if (!$path2D && !empty($row['drawing_2d_path']) && file_exists($rootAppDir . DIRECTORY_SEPARATOR . $row['drawing_2d_path'])) {
                $path2D = $row['drawing_2d_path'];
            }

            $path3D = $disk['path_3d'] ?? null;
            if (!$path3D && !empty($row['drawing_3d_path']) && file_exists($rootAppDir . DIRECTORY_SEPARATOR . $row['drawing_3d_path'])) {
                $path3D = $row['drawing_3d_path'];
            }

            $has2D = !empty($path2D);
            $has3D = !empty($path3D);
            $hasDrawing = $has2D || $has3D;

            // Apply Status Filter
            if ($status_filter === 'no_drawing' && ($has2D || $has3D)) {
                continue;
            }
            if ($status_filter === 'no_2d' && $has2D) {
                continue;
            }
            if ($status_filter === 'no_3d' && $has3D) {
                continue;
            }
            if ($status_filter === 'has_drawing' && (!$has2D && !$has3D)) {
                continue;
            }
            if ($status_filter === 'complete' && (!$has2D || !$has3D)) {
                continue;
            }

            $row['resolved_2d_path'] = $path2D;
            $row['resolved_3d_path'] = $path3D;
            $row['has_2d'] = $has2D;
            $row['has_3d'] = $has3D;
            $row['has_drawing'] = $hasDrawing;

            $filteredParts[] = $row;
        }

        // 4. Pagination Calculation
        $totalItems = count($filteredParts);
        $totalPages = ceil($totalItems / $limit);
        if ($totalPages < 1) $totalPages = 1;
        if ($page > $totalPages) $page = $totalPages;
        $offset = ($page - 1) * $limit;

        $drawings = array_slice($filteredParts, $offset, $limit);

        // 5. Fast Single-Pass Indexing for QC Documents (100% Struktur Baru uploads/parts/{part_code}/*.pdf)
        $basePartsDir = $rootAppDir . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'parts';

        foreach ($drawings as &$dRow) {
            $pCode = trim((string)($dRow['part_code'] ?? ''));
            $docFlags = [
                'pi'              => false,
                'qpoint'          => false,
                'pac'             => false,
                'standar_packing' => false
            ];

            if ($pCode !== '') {
                $partDir = $basePartsDir . DIRECTORY_SEPARATOR . $pCode;
                if (is_dir($partDir)) {
                    $pdfFiles = glob($partDir . DIRECTORY_SEPARATOR . '*.pdf') ?: [];
                    foreach ($pdfFiles as $pdfPath) {
                        $fName = basename($pdfPath);
                        $type = classify_part_file_type($fName);
                        if (isset($docFlags[$type])) {
                            $docFlags[$type] = true;
                        }
                    }
                }
            }

            $dRow['qc_docs'] = [
                'pi'              => ['has_document' => $docFlags['pi']],
                'qpoint'          => ['has_document' => $docFlags['qpoint']],
                'pac'             => ['has_document' => $docFlags['pac']],
                'standar_packing' => ['has_document' => $docFlags['standar_packing']]
            ];
        }
        unset($dRow);
    } catch (PDOException $e) {
        $drawings = [];
        $totalItems = 0;
        $totalPages = 1;
    }
}
?>

<div id="main-content-wrapper" class="flex-1 md:pl-64 flex flex-col min-h-screen transition-all duration-300">
    
    <?php require_once __DIR__ . '/../../layouts/navbar.php'; ?>

    <main class="flex-1 p-3 md:p-5 space-y-3">
        
        <?= render_flash() ?>

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
                    <select name="status_filter" onchange="this.form.submit()" class="form-input py-1 text-xs font-semibold text-slate-700" style="width: auto; min-width: 185px;">
                        <option value="all" <?= ($status_filter === 'all') ? 'selected' : '' ?>>Semua Status Drawing</option>
                        <option value="no_drawing" <?= ($status_filter === 'no_drawing') ? 'selected' : '' ?>>Belum Ada Berkas (2D &amp; 3D Kosong)</option>
                        <option value="no_2d" <?= ($status_filter === 'no_2d') ? 'selected' : '' ?>>Belum Ada Gambar 2D (PDF)</option>
                        <option value="no_3d" <?= ($status_filter === 'no_3d') ? 'selected' : '' ?>>Belum Ada Gambar 3D (.STP)</option>
                        <option value="has_drawing" <?= ($status_filter === 'has_drawing') ? 'selected' : '' ?>>Sudah Memiliki Berkas</option>
                        <option value="complete" <?= ($status_filter === 'complete') ? 'selected' : '' ?>>Berkas Lengkap (2D &amp; 3D)</option>
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
                        Total Part: <span class="font-bold text-slate-800"><?= $totalItems ?></span>
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
                                        ?>
                                        <div class="inline-flex items-center gap-1">
                                            <span class="px-1.5 py-0.5 rounded text-[9.5px] font-extrabold <?= $hasPi ? 'bg-indigo-100 text-indigo-800 border border-indigo-200' : 'bg-slate-100 text-slate-400' ?>" title="<?= $hasPi ? 'PI Tersedia' : 'PI Belum Ada' ?>">PI</span>
                                            <span class="px-1.5 py-0.5 rounded text-[9.5px] font-extrabold <?= $hasQp ? 'bg-amber-100 text-amber-800 border border-amber-200' : 'bg-slate-100 text-slate-400' ?>" title="<?= $hasQp ? 'Q-Point Tersedia' : 'Q-Point Belum Ada' ?>">QP</span>
                                            <span class="px-1.5 py-0.5 rounded text-[9.5px] font-extrabold <?= $hasPac ? 'bg-emerald-100 text-emerald-800 border border-emerald-200' : 'bg-slate-100 text-slate-400' ?>" title="<?= $hasPac ? 'PAC Tersedia' : 'PAC Belum Ada' ?>">PAC</span>
                                            <span class="px-1.5 py-0.5 rounded text-[9.5px] font-extrabold <?= $hasPack ? 'bg-orange-100 text-orange-800 border border-orange-200' : 'bg-slate-100 text-slate-400' ?>" title="<?= $hasPack ? 'Standar Packing Tersedia' : 'Standar Packing Belum Ada' ?>">PACK</span>
                                        </div>
                                    </td>

                                    <td class="px-4 py-3 text-[11px] text-slate-500 whitespace-nowrap">
                                        <?= format_date($row['updated_at'] ?? null) ?>
                                    </td>

                                    <td class="px-4 py-3 text-right whitespace-nowrap">
                                        <div class="inline-flex items-center justify-end gap-1 flex-wrap">
                                            <!-- View Detail / Viewer -->
                                            <?php if ($hasDrawing): ?>
                                                <a href="<?= base_url('modules/master_drawings/detail.php?part_id=' . $row['part_id']) ?>" 
                                                   class="btn-primary py-1 px-2.5 text-[11px]" title="Lihat Pratinjau Drawing">
                                                    Lihat
                                                </a>
                                            <?php endif; ?>

                                            <!-- Upload / Edit Drawing -->
                                            <a href="<?= base_url('modules/master_drawings/upload.php?part_id=' . $row['part_id']) ?>" 
                                               class="btn-secondary py-1 px-2.5 text-[11px]" title="Upload atau Perbarui File">
                                                <?= $hasDrawing ? 'Ganti File' : 'Upload' ?>
                                            </a>

                                            <!-- Delete Drawing (Secure POST with CSRF) -->
                                            <?php if ($hasDrawing || !empty($row['drawing_id'])): ?>
                                                <button type="button" 
                                                        onclick="confirmPostDelete('<?= base_url('modules/master_drawings/delete.php') ?>', '<?= !empty($row['drawing_id']) ? $row['drawing_id'] : '' ?>', 'Drawing Part <?= htmlspecialchars($row['part_code'], ENT_QUOTES) ?>', '<?= $csrfToken ?>', { part_id: '<?= $row['part_id'] ?>' })"
                                                        class="btn-icon text-rose-600 hover:bg-rose-50" title="Hapus Berkas Drawing">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
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
