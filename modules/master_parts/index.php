<?php
$breadcrumbCategory = "MASTER DATA";
$pageTitle = "Master Data Part";
$pageSubtitle = "Kelola data referensi Part Code dan Part Name yang digunakan pada seluruh sistem OQC";

require_once __DIR__ . '/../../layouts/header.php';
require_once __DIR__ . '/../../layouts/sidebar.php';

$pdo = getDB();
$parts = [];
$search = sanitize($_GET['search'] ?? '');
$sourceFilter = sanitize($_GET['source'] ?? '');

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
        $countSql = "SELECT COUNT(*) as total FROM master_parts p WHERE 1=1";
        $countParams = [];

        if (!empty($search)) {
            $countSql .= " AND (p.part_code LIKE :search1 OR p.part_name LIKE :search2)";
            $countParams[':search1'] = '%' . $search . '%';
            $countParams[':search2'] = '%' . $search . '%';
        }

        if (!empty($sourceFilter)) {
            $countSql .= " AND p.source = :source";
            $countParams[':source'] = $sourceFilter;
        }

        $stmtCount = $pdo->prepare($countSql);
        $stmtCount->execute($countParams);
        $totalItems = (int) $stmtCount->fetchColumn();

        $totalPages = ceil($totalItems / $limit);
        if ($totalPages < 1) $totalPages = 1;
        if ($page > $totalPages) $page = $totalPages;
        $offset = ($page - 1) * $limit;

        // 2. Fetch Data with Limit & Offset
        $sql = "SELECT p.*, m.name AS model_name, d.drawing_2d_path, d.drawing_3d_path 
                FROM master_parts p 
                LEFT JOIN master_models m ON p.model_id = m.id
                LEFT JOIN master_drawings d ON p.id = d.part_id 
                WHERE 1=1";
        $params = [];

        if (!empty($search)) {
            $sql .= " AND (p.part_code LIKE :search1 OR p.part_name LIKE :search2)";
            $params[':search1'] = '%' . $search . '%';
            $params[':search2'] = '%' . $search . '%';
        }

        if (!empty($sourceFilter)) {
            $sql .= " AND p.source = :source";
            $params[':source'] = $sourceFilter;
        }

        $sql .= " ORDER BY p.id DESC LIMIT :limit OFFSET :offset";

        $stmt = $pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
        $stmt->execute();
        
        $parts = $stmt->fetchAll();
    } catch (PDOException $e) {
        $parts = [];
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
                
                <!-- Inline Form Elements -->
                <form action="" method="GET" style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap; flex: 1;">
                    
                    <!-- Search Input (Precise Icon Inside Box) -->
                    <div style="position: relative; width: 220px; max-width: 100%;">
                        <svg style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%); width: 14px; height: 14px; color: #94a3b8; pointer-events: none;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                        </svg>
                        <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" 
                               placeholder="Cari Part Code / Nama..." class="form-input py-1 text-xs" style="padding-left: 30px; width: 100%;">
                    </div>

                    <!-- Source Filter Dropdown -->
                    <select name="source" class="form-input py-1 text-xs" style="width: 140px;">
                        <option value="">-- Semua --</option>
                        <option value="manual" <?= ($sourceFilter === 'manual') ? 'selected' : '' ?>>Manual Input</option>
                        <option value="auto_generated" <?= ($sourceFilter === 'auto_generated') ? 'selected' : '' ?>>Auto-Generated</option>
                    </select>

                    <!-- Limit Selector Dropdown -->
                    <select name="limit" onchange="this.form.submit()" class="form-input py-1 text-xs font-semibold text-slate-700" style="width: 90px;">
                        <option value="5" <?= ($limit == 5) ? 'selected' : '' ?>>5 / hal</option>
                        <option value="10" <?= ($limit == 10) ? 'selected' : '' ?>>10 / hal</option>
                        <option value="25" <?= ($limit == 25) ? 'selected' : '' ?>>25 / hal</option>
                        <option value="50" <?= ($limit == 50) ? 'selected' : '' ?>>50 / hal</option>
                    </select>

                    <button type="submit" class="btn-secondary py-1 px-2.5 text-xs">
                        Filter
                    </button>

                    <?php if (!empty($search) || !empty($sourceFilter) || $limit != 10): ?>
                        <a href="<?= base_url('modules/master_parts/index.php') ?>" class="text-[11px] text-rose-600 font-semibold hover:underline">Reset</a>
                    <?php endif; ?>
                </form>

                <!-- Create Button (Clean Icon + Text) -->
                <div>
                    <a href="<?= base_url('modules/master_parts/create.php') ?>" class="btn-primary py-1 px-3 text-xs">
                        <svg class="w-3.5 h-3.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                        </svg>
                        Tambah Part Baru
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
                            <th class="px-4 py-3">Level AQL</th>
                            <th class="px-4 py-3">Sumber Data</th>
                            <th class="px-4 py-3">Status Drawing (2D/3D)</th>
                            <th class="px-4 py-3">Tanggal Dibuat</th>
                            <th class="px-4 py-3 text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php if (empty($parts)): ?>
                            <tr>
                                <td colspan="8" class="px-4 py-8 text-center text-slate-400">
                                    Belum ada data Master Part. Silakan tambah data baru.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($parts as $index => $part): ?>
                                <tr class="hover:bg-slate-50/80 transition-colors">
                                    <td class="px-4 py-3 font-semibold text-slate-400"><?= $offset + $index + 1 ?></td>
                                    <td class="px-4 py-3 font-extrabold text-blue-700 font-mono tracking-tight">
                                        <?= htmlspecialchars($part['part_code']) ?>
                                        <?php if (!empty($part['model_name']) || !empty($part['model'])): ?>
                                            <span class="text-[10px] text-slate-400 font-sans block">Model: <?= htmlspecialchars($part['model_name'] ?? $part['model'] ?? '-') ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-3 font-semibold text-slate-800">
                                        <?= htmlspecialchars($part['part_name']) ?>
                                    </td>
                                    <td class="px-4 py-3 font-bold">
                                        <?php 
                                        $aqlLvl = $part['aql_level'] ?? 'G-II';
                                        if ($aqlLvl === 'G-I'): ?>
                                            <span class="badge badge-warning" title="General Level I - Longgar (Reduced Inspection)">G-I</span>
                                        <?php elseif ($aqlLvl === 'G-III'): ?>
                                            <span class="badge" style="background-color: #ffe4e6; color: #e11d48; border: 1px solid #fecdd3;" title="General Level III - Ketat (Tightened Inspection)">G-III</span>
                                        <?php else: ?>
                                            <span class="badge badge-blue" title="General Level II - Normal (Standar STI)">G-II</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-3">
                                        <?php if ($part['source'] === 'manual'): ?>
                                            <span class="badge badge-blue">Manual</span>
                                        <?php else: ?>
                                            <span class="badge badge-warning">Auto (DID/Kanban)</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="flex items-center space-x-1">
                                            <!-- Status 2D PDF -->
                                            <?php if (!empty($part['drawing_2d_path'])): ?>
                                                <span class="badge badge-success" title="Drawing 2D (PDF) Tersedia">2D PDF</span>
                                            <?php else: ?>
                                                <span class="badge badge-secondary" title="Drawing 2D Tidak Ada">No 2D</span>
                                            <?php endif; ?>

                                            <!-- Status 3D STP -->
                                            <?php if (!empty($part['drawing_3d_path'])): ?>
                                                <span class="badge badge-success" title="Drawing 3D (.STP) Tersedia">3D STP</span>
                                            <?php else: ?>
                                                <span class="badge badge-secondary" title="Drawing 3D Tidak Ada">No 3D</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 text-[11px] text-slate-500">
                                        <?= format_date($part['created_at']) ?>
                                    </td>
                                    <td class="px-4 py-3 text-right space-x-1">
                                        
                                        <!-- Upload / View Drawing Button -->
                                        <a href="<?= base_url('modules/master_drawings/detail.php?part_id=' . $part['id']) ?>" 
                                           class="btn-icon text-blue-600 hover:bg-blue-50" title="Lihat / Kelola Drawing 2D/3D">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                            </svg>
                                        </a>

                                        <!-- Edit Part Button -->
                                        <a href="<?= base_url('modules/master_parts/edit.php?id=' . $part['id']) ?>" 
                                           class="btn-icon text-indigo-600 hover:bg-indigo-50" title="Edit Part">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                            </svg>
                                        </a>

                                        <!-- Delete Part Button -->
                                        <button type="button" 
                                                onclick="confirmDelete('<?= base_url('modules/master_parts/delete.php?id=' . $part['id']) ?>', '<?= htmlspecialchars($part['part_code'], ENT_QUOTES) ?>')"
                                                class="btn-icon text-rose-600 hover:bg-rose-50" title="Hapus Part">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                            </svg>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Render Pagination Footer -->
            <?= render_pagination($page, $totalPages, $totalItems, $limit) ?>

        </div>

    </main>

<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>
