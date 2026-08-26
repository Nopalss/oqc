<?php
$breadcrumbCategory = "MASTER DATA";
$pageTitle = "Master Data Drawing (2D & 3D)";
$pageSubtitle = "Kelola berkas acuan gambar teknik 2D (PDF) dan 3D (.STP) per Part Code";

require_once __DIR__ . '/../../layouts/header.php';
require_once __DIR__ . '/../../layouts/sidebar.php';

$pdo = getDB();
$drawings = [];
$search = sanitize($_GET['search'] ?? '');

// Dynamic Limit (Default: 10 rows per page)
$limit = filter_input(INPUT_GET, 'limit', FILTER_VALIDATE_INT) ?: 10;
if (!in_array($limit, [5, 10, 25, 50, 100])) {
    $limit = 10;
}

$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?: 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;
$totalItems = 0;
$totalPages = 1;

if ($pdo) {
    try {
        // 1. Count Total Items
        $countSql = "SELECT COUNT(*) FROM master_parts p WHERE 1=1";
        $countParams = [];
        if (!empty($search)) {
            $countSql .= " AND (p.part_code LIKE :search1 OR p.part_name LIKE :search2)";
            $countParams[':search1'] = '%' . $search . '%';
            $countParams[':search2'] = '%' . $search . '%';
        }
        $stmtCount = $pdo->prepare($countSql);
        $stmtCount->execute($countParams);
        $totalItems = (int) $stmtCount->fetchColumn();

        $totalPages = ceil($totalItems / $limit);
        if ($totalPages < 1) $totalPages = 1;
        if ($page > $totalPages) $page = $totalPages;
        $offset = ($page - 1) * $limit;

        // 2. Fetch Data with Limit & Offset
        $sql = "SELECT p.id as part_id, p.part_code, p.part_name, 
                       d.id as drawing_id, d.drawing_2d_path, d.drawing_3d_path, d.updated_at
                FROM master_parts p
                LEFT JOIN master_drawings d ON p.id = d.part_id
                WHERE 1=1";
        $params = [];

        if (!empty($search)) {
            $sql .= " AND (p.part_code LIKE :search1 OR p.part_name LIKE :search2)";
            $params[':search1'] = '%' . $search . '%';
            $params[':search2'] = '%' . $search . '%';
        }

        $sql .= " ORDER BY p.part_code ASC LIMIT :limit OFFSET :offset";

        $stmt = $pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
        $stmt->execute();

        $drawings = $stmt->fetchAll();
    } catch (PDOException $e) {
        $drawings = [];
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

                    <!-- Limit Selector Dropdown -->
                    <select name="limit" onchange="this.form.submit()" class="form-input py-1 text-xs font-semibold text-slate-700" style="width: 90px;">
                        <option value="5" <?= ($limit == 5) ? 'selected' : '' ?>>5 / hal</option>
                        <option value="10" <?= ($limit == 10) ? 'selected' : '' ?>>10 / hal</option>
                        <option value="25" <?= ($limit == 25) ? 'selected' : '' ?>>25 / hal</option>
                        <option value="50" <?= ($limit == 50) ? 'selected' : '' ?>>50 / hal</option>
                    </select>

                    <button type="submit" class="btn-secondary py-1 px-2.5 text-xs">Cari</button>
                    <?php if (!empty($search) || $limit != 10): ?>
                        <a href="<?= base_url('modules/master_drawings/index.php') ?>" class="text-[11px] text-rose-600 font-semibold hover:underline">Reset</a>
                    <?php endif; ?>
                </form>

                <div class="text-xs text-slate-500 font-medium">
                    Total Part: <span class="font-bold text-slate-800"><?= $totalItems ?></span>
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
                            <th class="px-4 py-3 text-center">Gambar 2D (PDF)</th>
                            <th class="px-4 py-3 text-center">Gambar 3D (.STP)</th>
                            <th class="px-4 py-3">Update Terakhir</th>
                            <th class="px-4 py-3 text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php if (empty($drawings)): ?>
                            <tr>
                                <td colspan="7" class="px-4 py-8 text-center text-slate-400">
                                    Belum ada data Master Part & Drawing.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($drawings as $index => $row): ?>
                                <tr class="hover:bg-slate-50/80 transition-colors">
                                    <td class="px-4 py-3 font-semibold text-slate-400"><?= $offset + $index + 1 ?></td>
                                    <td class="px-4 py-3 font-extrabold text-blue-700 font-mono">
                                        <?= htmlspecialchars($row['part_code']) ?>
                                    </td>
                                    <td class="px-4 py-3 font-semibold text-slate-800">
                                        <?= htmlspecialchars($row['part_name']) ?>
                                    </td>

                                    <!-- 2D PDF Status -->
                                    <td class="px-4 py-3 text-center">
                                        <?php if (!empty($row['drawing_2d_path'])): ?>
                                            <span class="badge badge-success">✓ 2D Ready</span>
                                        <?php else: ?>
                                            <span class="badge badge-secondary">Belum Upload</span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- 3D STP Status -->
                                    <td class="px-4 py-3 text-center">
                                        <?php if (!empty($row['drawing_3d_path'])): ?>
                                            <span class="badge badge-success">✓ 3D Ready</span>
                                        <?php else: ?>
                                            <span class="badge badge-secondary">Belum Upload</span>
                                        <?php endif; ?>
                                    </td>

                                    <td class="px-4 py-3 text-[11px] text-slate-500">
                                        <?= format_date($row['updated_at'] ?? null) ?>
                                    </td>

                                    <td class="px-4 py-3 text-right space-x-1">
                                        <!-- View Detail / Viewer -->
                                        <?php if (!empty($row['drawing_2d_path']) || !empty($row['drawing_3d_path'])): ?>
                                            <a href="<?= base_url('modules/master_drawings/detail.php?part_id=' . $row['part_id']) ?>" 
                                               class="btn-primary py-1 px-2 text-[11px]" title="Lihat Drawing Viewer">
                                                Viewer
                                            </a>
                                        <?php endif; ?>

                                        <!-- Upload / Edit Drawing -->
                                        <a href="<?= base_url('modules/master_drawings/upload.php?part_id=' . $row['part_id']) ?>" 
                                           class="btn-secondary py-1 px-2 text-[11px]" title="Upload / Update File Drawing">
                                            <?= (!empty($row['drawing_2d_path']) || !empty($row['drawing_3d_path'])) ? 'Edit File' : 'Upload' ?>
                                        </a>

                                        <!-- Delete Drawing -->
                                        <?php if (!empty($row['drawing_id'])): ?>
                                            <button type="button" 
                                                    onclick="confirmDelete('<?= base_url('modules/master_drawings/delete.php?id=' . $row['drawing_id']) ?>', 'Drawing <?= htmlspecialchars($row['part_code'], ENT_QUOTES) ?>')"
                                                    class="btn-icon text-rose-600 hover:bg-rose-50" title="Hapus Berkas Drawing">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                                </svg>
                                            </button>
                                        <?php endif; ?>
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
