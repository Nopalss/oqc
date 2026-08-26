<?php
$breadcrumbCategory = "PENGATURAN";
$pageTitle = "User Management";
$pageSubtitle = "Kelola akun pengguna, hak akses role, dan status aktifasi pengguna sistem";

require_once __DIR__ . '/../../layouts/header.php';
require_once __DIR__ . '/../../layouts/sidebar.php';

$pdo = getDB();
$users = [];
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

// Initialize session mock storage if DB is not connected yet
if (!isset($_SESSION['users_mock'])) {
    $_SESSION['users_mock'] = [
        1 => ['id' => 1, 'name' => 'Budi Santoso', 'username' => 'budi', 'role' => 'admin', 'status' => 'active', 'created_at' => '2026-08-10 10:00:00'],
        2 => ['id' => 2, 'name' => 'Siti Rahma', 'username' => 'siti', 'role' => 'qc_inspector', 'status' => 'active', 'created_at' => '2026-08-12 14:30:00'],
        3 => ['id' => 3, 'name' => 'Ahmad Fauzi', 'username' => 'ahmad', 'role' => 'supervisor_viewer', 'status' => 'inactive', 'created_at' => '2026-08-15 09:15:00'],
    ];
}

if ($pdo) {
    try {
        // 1. Count Total
        $countSql = "SELECT COUNT(*) FROM users WHERE 1=1";
        $countParams = [];
        if (!empty($search)) {
            $countSql .= " AND (name LIKE :search1 OR username LIKE :search2)";
            $countParams[':search1'] = '%' . $search . '%';
            $countParams[':search2'] = '%' . $search . '%';
        }
        $stmtCount = $pdo->prepare($countSql);
        $stmtCount->execute($countParams);
        $totalItems = (int) $stmtCount->fetchColumn();

        $totalPages = ceil($totalItems / $limit);
        if ($totalPages < 1) $totalPages = 1;
        if ($page > $totalPages) $page = $totalPages;

        // 2. Fetch Data
        $sql = "SELECT * FROM users WHERE 1=1";
        $params = [];
        if (!empty($search)) {
            $sql .= " AND (name LIKE :search1 OR username LIKE :search2)";
            $params[':search1'] = '%' . $search . '%';
            $params[':search2'] = '%' . $search . '%';
        }
        $sql .= " ORDER BY id DESC LIMIT :limit OFFSET :offset";

        $stmt = $pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
        $stmt->execute();

        $users = $stmt->fetchAll();
    } catch (PDOException $e) {
        $users = array_values($_SESSION['users_mock']);
        $totalItems = count($users);
    }
} else {
    $users = array_values($_SESSION['users_mock']);
    $totalItems = count($users);
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
                               placeholder="Cari Nama / Username..." class="form-input py-1 text-xs" style="padding-left: 30px; width: 100%;">
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
                        <a href="<?= base_url('modules/users/index.php') ?>" class="text-[11px] text-rose-600 font-semibold hover:underline">Reset</a>
                    <?php endif; ?>
                </form>

                <div>
                    <a href="<?= base_url('modules/users/create.php') ?>" class="btn-primary py-1 px-3 text-xs">
                        <svg class="w-3.5 h-3.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                        </svg>
                        Tambah User Baru
                    </a>
                </div>
            </div>
        </div>

        <!-- Table Card Container -->
        <div class="card p-0 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs text-slate-700">
                    <thead class="bg-slate-50 text-slate-600 font-bold border-b border-slate-200/80 uppercase tracking-wider text-[10px]">
                        <tr>
                            <th class="px-4 py-3">No</th>
                            <th class="px-4 py-3">Nama Lengkap</th>
                            <th class="px-4 py-3">Username</th>
                            <th class="px-4 py-3">Role Akses</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3">Tanggal Dibuat</th>
                            <th class="px-4 py-3 text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php if (empty($users)): ?>
                            <tr>
                                <td colspan="7" class="px-4 py-8 text-center text-slate-400">
                                    Belum ada data user. Silakan tambah data baru.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($users as $index => $user): ?>
                                <tr class="hover:bg-slate-50/80 transition-colors">
                                    <td class="px-4 py-3 font-semibold text-slate-400"><?= $offset + $index + 1 ?></td>
                                    <td class="px-4 py-3 font-bold text-slate-800">
                                        <div class="flex items-center space-x-2">
                                            <div class="w-7 h-7 rounded-full bg-blue-100 text-blue-700 font-bold flex items-center justify-center text-xs">
                                                <?= strtoupper(substr($user['name'], 0, 1)) ?>
                                            </div>
                                            <span><?= htmlspecialchars($user['name']) ?></span>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 font-mono font-semibold text-slate-700"><?= htmlspecialchars($user['username'] ?? $user['email'] ?? '-') ?></td>
                                    <td class="px-4 py-3">
                                        <span class="badge badge-blue">
                                            <?= htmlspecialchars(strtoupper(str_replace('_', ' ', $user['role']))) ?>
                                        </span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <?php if (in_array(strtolower($user['status'] ?? ''), ['active', 'aktif'])): ?>
                                            <span class="badge badge-success">Aktif</span>
                                        <?php else: ?>
                                            <span class="badge badge-danger">Non-Aktif</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-3 text-[11px] text-slate-500">
                                        <?= format_date($user['created_at'] ?? date('Y-m-d H:i:s')) ?>
                                    </td>
                                    <td class="px-4 py-3 text-right space-x-1">
                                        <!-- Edit Button -->
                                        <a href="<?= base_url('modules/users/edit.php?id=' . $user['id']) ?>" 
                                           class="btn-icon text-indigo-600 hover:bg-indigo-50" title="Edit">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                            </svg>
                                        </a>

                                        <!-- Delete Button -->
                                        <button type="button" 
                                                onclick="confirmDelete('<?= base_url('modules/users/delete.php?id=' . $user['id']) ?>', 'User <?= htmlspecialchars($user['name'], ENT_QUOTES) ?>')"
                                                class="btn-icon text-rose-600 hover:bg-rose-50" title="Hapus">
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
