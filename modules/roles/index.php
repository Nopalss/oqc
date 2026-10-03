<?php
$breadcrumbCategory = "PENGATURAN";
$pageTitle = "Manajemen Role & Hak Akses";
$pageSubtitle = "Kelola peran pengguna dan matriks hak akses menu pada sistem OQC";

require_once __DIR__ . '/../../layouts/header.php';
require_once __DIR__ . '/../../layouts/sidebar.php';

// Enforce RBAC guard
require_menu_access('roles');

$pdo = getDB();
$roles = [];
$search = sanitize($_GET['search'] ?? '');

if ($pdo) {
    try {
        $sql = "
            SELECT 
                r.id, 
                r.role_name, 
                r.display_name, 
                r.description, 
                r.is_system, 
                r.created_at,
                (SELECT COUNT(*) FROM users u WHERE u.role = r.role_name) AS user_count,
                (SELECT COUNT(*) FROM role_permissions rp WHERE rp.role_id = r.id AND rp.can_view = 1) AS perm_count
            FROM roles r
            WHERE 1=1
        ";
        $params = [];
        if (!empty($search)) {
            $sql .= " AND (r.role_name LIKE :s1 OR r.display_name LIKE :s2 OR r.description LIKE :s3)";
            $params[':s1'] = '%' . $search . '%';
            $params[':s2'] = '%' . $search . '%';
            $params[':s3'] = '%' . $search . '%';
        }
        $sql .= " ORDER BY r.is_system DESC, r.id ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $roles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        set_flash('danger', 'Gagal memuat data role: ' . $e->getMessage());
    }
}
?>

<div id="main-content-wrapper" class="flex-1 md:pl-64 flex flex-col min-h-screen transition-all duration-300">
    
    <?php require_once __DIR__ . '/../../layouts/navbar.php'; ?>

    <main class="flex-1 p-3 md:p-5 space-y-3">
        
        <?= render_flash() ?>

        <!-- Filter & Action Bar -->
        <div class="card p-2.5 bg-white border border-slate-200/80 rounded-xl shadow-xs">
            <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px; flex-wrap: wrap;">
                
                <form action="" method="GET" style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap; flex: 1;">
                    <div style="position: relative; width: 260px; max-width: 100%;">
                        <svg style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%); width: 14px; height: 14px; color: #94a3b8; pointer-events: none;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                        </svg>
                        <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" 
                               placeholder="Cari Role / Display Name..." class="form-input py-1 text-xs" style="padding-left: 30px; width: 100%;">
                    </div>

                    <button type="submit" class="btn-secondary py-1 px-2.5 text-xs">Cari</button>
                    <?php if (!empty($search)): ?>
                        <a href="<?= base_url('modules/roles/index.php') ?>" class="text-[11px] text-rose-600 font-semibold hover:underline">Reset</a>
                    <?php endif; ?>
                </form>

                <div>
                    <a href="<?= base_url('modules/roles/create.php') ?>" class="btn-primary py-1 px-3 text-xs">
                        <svg class="w-3.5 h-3.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                        </svg>
                        Tambah Role Baru
                    </a>
                </div>
            </div>
        </div>

        <!-- Table Card Container -->
        <div class="card p-0 overflow-hidden bg-white border border-slate-200/80 rounded-xl shadow-xs">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs text-slate-700">
                    <thead class="bg-slate-50 text-slate-600 font-bold border-b border-slate-200/80 uppercase tracking-wider text-[10px]">
                        <tr>
                            <th class="px-4 py-3 text-center" style="width: 50px;">No</th>
                            <th class="px-4 py-3">Nama Role</th>
                            <th class="px-4 py-3 text-center">Hak Akses Menu</th>
                            <th class="px-4 py-3 text-center">Jml Pengguna</th>
                            <th class="px-4 py-3 text-center">Tipe</th>
                            <th class="px-4 py-3 text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php if (empty($roles)): ?>
                            <tr>
                                <td colspan="6" class="px-4 py-8 text-center text-slate-400">
                                    Belum ada data role yang cocok dengan pencarian.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($roles as $idx => $r): ?>
                                <tr class="hover:bg-slate-50/70 transition-colors">
                                    <td class="px-4 py-3 text-center font-medium text-slate-500">
                                        <?= $idx + 1 ?>
                                    </td>
                                    <td class="px-4 py-3 font-bold text-slate-800 text-sm">
                                        <?= htmlspecialchars($r['display_name']) ?>
                                    </td>
                                    <td class="px-4 py-3 text-center">
                                        <?php if ($r['role_name'] === 'admin'): ?>
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                                Semua Menu (Full)
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold bg-blue-50 text-blue-700 border border-blue-200">
                                                <?= (int)$r['perm_count'] ?> Menu
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-3 text-center font-semibold text-slate-700">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] bg-slate-100 text-slate-700">
                                            <?= (int)$r['user_count'] ?> user
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-center">
                                        <?php if ((int)$r['is_system'] === 1): ?>
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider bg-purple-50 text-purple-700 border border-purple-200">
                                                Sistem
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider bg-slate-100 text-slate-600 border border-slate-200">
                                                Kustom
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-3 text-right space-x-1 whitespace-nowrap">
                                        <!-- Edit Button -->
                                        <a href="<?= base_url('modules/roles/edit.php?id=' . (int)$r['id']) ?>" 
                                           class="inline-flex items-center px-2 py-1 rounded text-[11px] font-medium text-blue-700 bg-blue-50 hover:bg-blue-100 border border-blue-200 transition-colors"
                                           title="Edit Role & Hak Akses">
                                            <svg class="w-3.5 h-3.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                            </svg>
                                            Edit
                                        </a>

                                        <!-- Delete Button (Only for non-system roles) -->
                                        <?php if ((int)$r['is_system'] !== 1): ?>
                                            <button type="button" 
                                                    onclick="confirmDelete(<?= (int)$r['id'] ?>, '<?= htmlspecialchars(addslashes($r['display_name'])) ?>', <?= (int)$r['user_count'] ?>)"
                                                    class="inline-flex items-center px-2 py-1 rounded text-[11px] font-medium text-rose-700 bg-rose-50 hover:bg-rose-100 border border-rose-200 transition-colors"
                                                    title="Hapus Role">
                                                <svg class="w-3.5 h-3.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                                </svg>
                                                Hapus
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </main>

    <!-- Hidden Delete Form -->
    <form id="deleteForm" action="<?= base_url('modules/roles/delete.php') ?>" method="POST" style="display: none;">
        <input type="hidden" name="id" id="deleteId">
    </form>

    <script>
    function confirmDelete(id, name, userCount) {
        if (userCount > 0) {
            alert('Tidak dapat menghapus role "' + name + '" karena masih digunakan oleh ' + userCount + ' pengguna aktif! Silakan alihkan role pengguna terlebih dahulu.');
            return;
        }

        if (confirm('Apakah Anda yakin ingin menghapus role "' + name + '" beserta seluruh izin aksesnya?')) {
            document.getElementById('deleteId').value = id;
            document.getElementById('deleteForm').submit();
        }
    }
    </script>

<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>
