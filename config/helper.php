<?php
/**
 * Helper Utility Functions
 */

require_once __DIR__ . '/app.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Generate full URL from relative path
 */
function base_url($path = '') {
    $cleanPath = ltrim($path, '/');
    return BASE_URL . ($cleanPath ? '/' . $cleanPath : '');
}

/**
 * Sanitize user input strings
 */
function sanitize($data) {
    if (is_array($data)) {
        return array_map('sanitize', $data);
    }
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

/**
 * Safely parse quantity string/number into clean integer (strips commas, dots, spaces)
 */
function clean_qty($data) {
    if (is_numeric($data)) {
        return (int)$data;
    }
    $cleaned = preg_replace('/[^\d]/', '', (string)$data);
    return (int)$cleaned;
}

/**
 * Perform URL redirection
 */
function redirect($path) {
    $target = (strpos($path, 'http://') === 0 || strpos($path, 'https://') === 0) ? $path : base_url($path);
    header("Location: " . $target);
    exit;
}

/**
 * Standard Shift Detection based on Timestamp
 * Regular (Monday - Friday, Sunday):
 *   Shift 1: 07:00 - 15:00
 *   Shift 2: 15:00 - 23:00
 *   Shift 3: 23:00 - 07:00
 * Saturday (Half Day / 5-hour intervals):
 *   Shift 1: 07:00 - 12:00
 *   Shift 2: 12:00 - 17:00
 *   Shift 3: 17:00 - 22:00 (dan lembur malam sebelum 07:00)
 */
if (!function_exists('getShiftName')) {
    function getShiftName($timestamp) {
        if (empty($timestamp)) return 'Shift 1';
        $ts = is_numeric($timestamp) ? (int)$timestamp : strtotime($timestamp);
        if (!$ts) return 'Shift 1';

        $dayOfWeek = (int)date('N', $ts); // 1 = Senin, ..., 6 = Sabtu, 7 = Minggu
        $hour      = (int)date('H', $ts);

        // KONDISI KHUSUS: HARI SABTU (Setengah Hari / 5 Jam per shift)
        if ($dayOfWeek === 6) {
            if ($hour >= 7 && $hour < 12) {
                return 'Shift 1';
            } elseif ($hour >= 12 && $hour < 17) {
                return 'Shift 2';
            } elseif ($hour >= 17 && $hour < 22) {
                return 'Shift 3';
            } else {
                return 'Shift 3';
            }
        }

        // KONDISI REGULER: SENIN - JUMAT & MINGGU
        if ($hour >= 7 && $hour < 15) {
            return 'Shift 1';
        } elseif ($hour >= 15 && $hour < 23) {
            return 'Shift 2';
        } else {
            return 'Shift 3';
        }
    }
}

/**
 * Set Session Flash Message (Success, Error, Warning, Info)
 */
function set_flash($type, $message) {
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message
    ];
}

/**
 * Retrieve & clear Session Flash Message
 */
function get_flash() {
    static $consumedFlash = null;
    static $alreadyConsumed = false;

    if ($alreadyConsumed) {
        return $consumedFlash;
    }

    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }

    if (isset($_SESSION['flash'])) {
        $consumedFlash = $_SESSION['flash'];
        unset($_SESSION['flash']);
    }

    $alreadyConsumed = true;
    return $consumedFlash;
}

/**
 * Render Flash Alert HTML badge
 */
function render_flash() {
    static $rendered = false;
    if ($rendered) {
        return '';
    }

    $flash = get_flash();
    if (!$flash) return '';

    $rendered = true;

    $type = $flash['type'];
    $msg = $flash['message'];

    $colorClasses = [
        'success' => 'bg-emerald-50 text-emerald-800 border-emerald-200',
        'error'   => 'bg-rose-50 text-rose-800 border-rose-200',
        'warning' => 'bg-amber-50 text-amber-800 border-amber-200',
        'info'    => 'bg-sky-50 text-sky-800 border-sky-200',
        'danger'  => 'bg-rose-50 text-rose-800 border-rose-200',
    ];

    $class = $colorClasses[$type] ?? $colorClasses['info'];

    $swalType = ($type === 'danger') ? 'error' : $type;
    $safeMsg = json_encode($msg);

    return '
    <div class="mb-4 p-3 rounded-xl border flex items-center justify-between shadow-xs transition-all duration-300 ' . $class . '" id="flash-alert">
        <div class="flex items-center space-x-2">
            <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
            <span class="text-xs font-semibold">' . htmlspecialchars($msg) . '</span>
        </div>
        <button type="button" onclick="document.getElementById(\'flash-alert\').remove()" class="text-current opacity-70 hover:opacity-100 p-1 rounded-lg">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
            </svg>
        </button>
    </div>
    <script>
    document.addEventListener("DOMContentLoaded", function() {
        if (typeof showToast === "function") {
            showToast(' . json_encode($swalType) . ', ' . $safeMsg . ');
        }
    });
    </script>';
}

/**
 * Check active menu route for active styling
 */
function is_active_menu($menuName) {
    $currentUri = $_SERVER['REQUEST_URI'] ?? '';
    return (strpos($currentUri, $menuName) !== false) ? true : false;
}

/**
 * Format Indonesia Date
 */
function format_date($dateString) {
    if (!$dateString) return '-';
    $time = strtotime($dateString);
    return date('d M Y, H:i', $time);
}

/**
 * Render Reusable HTML Pagination Bar (Always 1 Horizontal Line)
 */
function render_pagination($currentPage, $totalPages, $totalItems, $perPage = 10) {
    $start = $totalItems > 0 ? (($currentPage - 1) * $perPage) + 1 : 0;
    $end = min($currentPage * $perPage, $totalItems);

    // Build query params preserving search & filter
    $queryParams = $_GET;
    
    $buildUrl = function($page) use ($queryParams) {
        $queryParams['page'] = $page;
        return '?' . http_build_query($queryParams);
    };

    $html = '<div class="px-4 py-2.5 bg-white border-t border-slate-200/80 text-xs text-slate-500 font-medium" style="display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;">';
    
    // Left info
    $html .= '<div>';
    $html .= 'Menampilkan <span class="font-bold text-slate-800">' . $start . '</span> - <span class="font-bold text-slate-800">' . $end . '</span> dari <span class="font-bold text-slate-800">' . $totalItems . '</span> data';
    $html .= '</div>';

    // Right Controls (Force 1 Horizontal Line)
    $html .= '<div style="display: flex; align-items: center; gap: 4px;">';
    
    // Prev button
    if ($currentPage > 1) {
        $html .= '<a href="' . $buildUrl($currentPage - 1) . '" class="btn-secondary py-1 px-2.5 text-xs">&laquo; Prev</a>';
    } else {
        $html .= '<span class="px-2.5 py-1 text-slate-300 border border-slate-200 rounded-lg text-xs cursor-not-allowed select-none">&laquo; Prev</span>';
    }

    // Page Numbers
    $window = 2;
    $maxPages = max(1, $totalPages);
    $startPage = max(1, $currentPage - $window);
    $endPage = min($maxPages, $currentPage + $window);

    if ($startPage > 1) {
        $html .= '<a href="' . $buildUrl(1) . '" class="btn-secondary py-1 px-2.5 text-xs">1</a>';
        if ($startPage > 2) {
            $html .= '<span class="px-1.5 py-1 text-slate-400">...</span>';
        }
    }

    for ($p = $startPage; $p <= $endPage; $p++) {
        if ($p == $currentPage) {
            $html .= '<span class="bg-blue-600 text-white font-bold px-3 py-1 rounded-lg text-xs shadow-xs">' . $p . '</span>';
        } else {
            $html .= '<a href="' . $buildUrl($p) . '" class="btn-secondary py-1 px-2.5 text-xs">' . $p . '</a>';
        }
    }

    if ($endPage < $totalPages) {
        if ($endPage < $totalPages - 1) {
            $html .= '<span class="px-1.5 py-1 text-slate-400">...</span>';
        }
        $html .= '<a href="' . $buildUrl($totalPages) . '" class="btn-secondary py-1 px-2.5 text-xs">' . $totalPages . '</a>';
    }

    // Next button
    if ($currentPage < $totalPages) {
        $html .= '<a href="' . $buildUrl($currentPage + 1) . '" class="btn-secondary py-1 px-2.5 text-xs">Next &raquo;</a>';
    } else {
        $html .= '<span class="px-2.5 py-1 text-slate-300 border border-slate-200 rounded-lg text-xs cursor-not-allowed select-none">Next &raquo;</span>';
    }

    $html .= '</div>';
    $html .= '</div>';

    return $html;
}

/**
 * Generate or retrieve CSRF token from current session
 */
function generate_csrf_token() {
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Render HTML hidden input containing CSRF token
 */
function csrf_field() {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(generate_csrf_token()) . '">';
}

/**
 * Verify incoming CSRF token against session
 */
function verify_csrf_token($token = null) {
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    if (empty($sessionToken)) {
        return false;
    }
    $incoming = $token ?? ($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    return hash_equals($sessionToken, (string)$incoming);
}

/**
 * Enforce CSRF verification; redirect back with flash error if invalid
 */
function csrf_verify() {
    if (!verify_csrf_token()) {
        set_flash('error', 'Validasi keamanan (CSRF Token) gagal atau sesi Anda telah berakhir. Silakan muat ulang halaman.');
        $back = $_SERVER['HTTP_REFERER'] ?? 'index.php';
        redirect($back);
    }
}

/**
 * Check if current user is logged in
 */
function is_logged_in() {
    // Only trust user_id — prevents bypass via partial session data
    return !empty($_SESSION['user_id']) && is_numeric($_SESSION['user_id']) && (int)$_SESSION['user_id'] > 0;
}

/**
 * Require user authentication, redirect to login page if not logged in
 */
function require_login() {
    if (!is_logged_in()) {
        set_flash('warning', 'Silakan masuk ke akun Anda terlebih dahulu untuk mengakses sistem.');
        redirect('login.php');
    }
}

/**
 * Get current logged in user details
 */
function current_user() {
    // Return null-safe values — no default fallback to prevent ghost admin actions
    return [
        'id'      => isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0,
        'name'    => $_SESSION['user_name'] ?? '',
        'username'=> $_SESSION['username'] ?? '',
        'role'    => $_SESSION['user_role'] ?? 'guest',
        'role_id' => isset($_SESSION['user_role_id']) ? (int)$_SESSION['user_role_id'] : null,
    ];
}

/**
 * Load user permissions from database into session
 */
function load_user_permissions($pdo, $role_name) {
    if (!$pdo || empty($role_name)) {
        $_SESSION['permissions'] = [];
        return;
    }

    // If role is admin, grant access to all menus
    if ($role_name === 'admin') {
        $_SESSION['permissions'] = ['*'];
        return;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT rp.menu_key 
            FROM role_permissions rp
            JOIN roles r ON rp.role_id = r.id
            WHERE r.role_name = :role_name AND rp.can_view = 1
        ");
        $stmt->execute([':role_name' => $role_name]);
        $_SESSION['permissions'] = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (PDOException $e) {
        $_SESSION['permissions'] = [];
    }
}

/**
 * Check if the logged-in user can access a specific menu/module
 */
function can_access($menu_key) {
    $user = current_user();
    if (empty($user['id'])) {
        return false;
    }

    // Role admin always has access
    if ($user['role'] === 'admin') {
        return true;
    }

    $permissions = $_SESSION['permissions'] ?? null;
    
    // Fallback if permissions not loaded in session yet
    if ($permissions === null) {
        try {
            $pdo = getDB();
            load_user_permissions($pdo, $user['role']);
            $permissions = $_SESSION['permissions'] ?? [];
        } catch (Exception $e) {
            $permissions = [];
        }
    }

    if (in_array('*', $permissions, true)) {
        return true;
    }

    return in_array($menu_key, $permissions, true);
}

/**
 * Check if the logged-in user has permission for a specific menu (Alias of can_access)
 */
function has_menu_access($menu_key) {
    return can_access($menu_key);
}

/**
 * Find the first URL that the current user has permission to view
 */
function get_first_accessible_url() {
    $menuUrls = [
        'dashboard'       => 'modules/dashboard/index.php',
        'defect_analysis' => 'modules/defect_analysis/index.php',
        'inspection'      => 'modules/inspection/index.php',
        'safety_stock'    => 'modules/safety_stock/index.php',
        'daily_report'    => 'modules/daily_report/index.php',
        'did'             => 'modules/did/index.php',
        'kanban'          => 'modules/kanban/index.php',
        'master_parts'     => 'modules/master_parts/index.php',
        'master_models'    => 'modules/master_models/index.php',
        'master_customers' => 'modules/master_customers/index.php',
        'master_drawings'  => 'modules/master_drawings/index.php',
        'master_defects'   => 'modules/master_defects/index.php',
        'users'            => 'modules/users/index.php',
        'roles'            => 'modules/roles/index.php',
    ];

    foreach ($menuUrls as $key => $url) {
        if (can_access($key)) {
            return $url;
        }
    }
    return null;
}

/**
 * Require menu access permission; redirect to accessible page with flash message if forbidden
 */
function require_menu_access($menu_key) {
    require_login();
    if (!can_access($menu_key)) {
        set_flash('danger', 'Akses ditolak: Anda tidak memiliki izin untuk membuka menu tersebut.');
        $fallback = get_first_accessible_url();
        if ($fallback) {
            redirect($fallback);
        } else {
            set_flash('danger', 'Akun Anda belum memiliki izin untuk mengakses menu sistem. Hubungi administrator.');
            redirect('logout.php');
        }
    }
}

/**
 * Get list of all system menus categorized for RBAC configuration
 */
function get_system_menus() {
    return [
        'Operasional' => [
            'dashboard'       => ['label' => 'Dashboard Laporan', 'desc' => 'Melihat ringkasan metrik dan grafik performa OQC'],
            'defect_analysis' => ['label' => 'Analisis Defect', 'desc' => 'Leaderboard dan tren cacat visual produk'],
            'inspection'      => ['label' => 'Scan Inspeksi', 'desc' => 'Proses scan label dan pencatatan hasil inspeksi'],
            'safety_stock'    => ['label' => 'Safety Stock', 'desc' => 'Monitoring stok barang hasil realisasi inspeksi'],
            'daily_report'    => ['label' => 'Laporan Harian', 'desc' => 'Rekapitulasi laporan inspeksi harian dan export excel']
        ],
        'Data Referensi' => [
            'did'    => ['label' => 'Upload DID', 'desc' => 'Import dan status kelengkapan data DID/dimensi'],
            'kanban' => ['label' => 'Planning Inspeksi', 'desc' => 'Import kanban dan alokasi safety stock']
        ],
        'Master Data' => [
            'master_parts'     => ['label' => 'Master Data Part', 'desc' => 'Pengelolaan data part number (FR-4)'],
            'master_models'    => ['label' => 'Master Data Model', 'desc' => 'Pengelolaan data jenis/tipe model produk'],
            'master_customers' => ['label' => 'Master Data Customer', 'desc' => 'Pengelolaan data pelanggan/tujuan delivery'],
            'master_drawings'  => ['label' => 'Master Data Drawing', 'desc' => 'Pengelolaan gambar referensi teknik 2D/3D'],
            'master_defects'   => ['label' => 'Master Jenis Defect', 'desc' => 'Pengelolaan master katalog jenis cacat']
        ],
        'Pengaturan' => [
            'users' => ['label' => 'User Management', 'desc' => 'Kelola akun pengguna, reset password, & assign role'],
            'roles' => ['label' => 'Manajemen Role', 'desc' => 'Kelola grup role dan matriks hak akses menu']
        ]
    ];
}

/**
 * Synchronize Lifecycle Status of a Kanban Item based on all its inspection sessions
 * 
 * Status hierarchy:
 * 1. 'in_progress': If at least 1 session is currently in_progress
 * 2. 'rejected': If latest session ended in rejection and not yet reinspected to pass
 * 3. 'completed': If total scanned passed Qty >= target Qty (and target Qty > 0)
 * 4. 'partial': If total scanned passed Qty > 0 but < target Qty
 * 5. 'uninspected': If no sessions exist or 0 qty inspected
 */
function syncKanbanStatus($pdo, $kanbanItemId) {
    $kid = (int)$kanbanItemId;
    if (!$pdo || $kid <= 0) {
        return null;
    }

    try {
        $stmtK = $pdo->prepare("SELECT id, qty, status FROM kanban_items WHERE id = :kid LIMIT 1");
        $stmtK->execute([':kid' => $kid]);
        $kItem = $stmtK->fetch(PDO::FETCH_ASSOC);
        if (!$kItem) {
            return null;
        }

        $targetQty = (int)$kItem['qty'];

        $stmtS = $pdo->prepare("
            SELECT s.id, s.status, s.total_scanned_qty, s.excess_qty, s.is_reinspection, s.parent_session_id,
                   (SELECT COUNT(*) FROM inspection_session_lots isl WHERE isl.inspection_session_id = s.id) AS lot_count,
                   (SELECT COALESCE(SUM(CASE WHEN isl.lot_result IN ('passed', 'skipped') AND (isl.lot_status IS NULL OR isl.lot_status != 'replaced') THEN isl.qty ELSE 0 END), 0)
                    FROM inspection_session_lots isl 
                    WHERE isl.inspection_session_id = s.id) AS lot_passed_qty,
                   (SELECT COUNT(*) FROM inspection_session_lots isl_r WHERE isl_r.inspection_session_id = s.id AND (isl_r.lot_result = 'rejected' OR isl_r.lot_status IN ('rejected', 'ng_found'))) AS lot_rejected_count
            FROM inspection_sessions s 
            WHERE s.kanban_item_id = :kid 
            ORDER BY s.id ASC
        ");
        $stmtS->execute([':kid' => $kid]);
        $sessions = $stmtS->fetchAll(PDO::FETCH_ASSOC);

        $newStatus = 'uninspected';

        if (!empty($sessions)) {
            $hasInProgress = false;
            $hasRejected = false;
            $passedQty = 0;
            $latestSession = end($sessions);

            foreach ($sessions as $s) {
                if ($s['status'] === 'in_progress') {
                    $hasInProgress = true;
                }
                
                $sessPassed = 0;
                if ((int)$s['lot_count'] > 0) {
                    $sessPassed = (int)$s['lot_passed_qty'];
                    if ((int)$s['lot_rejected_count'] > 0 || $s['status'] === 'rejected') {
                        $hasRejected = true;
                    }
                } elseif ($s['status'] === 'passed') {
                    $sessPassed = (int)$s['total_scanned_qty'];
                } elseif ($s['status'] === 'rejected') {
                    $hasRejected = true;
                }
                $passedQty += $sessPassed;
            }

            if ($hasInProgress) {
                $newStatus = 'in_progress';
            } elseif ($targetQty > 0 && $passedQty >= $targetQty) {
                $newStatus = 'completed';
            } elseif ($hasRejected || $latestSession['status'] === 'rejected') {
                $newStatus = ($passedQty > 0) ? 'partial' : 'rejected';
            } elseif ($passedQty > 0) {
                $newStatus = 'partial';
            } else {
                $newStatus = 'uninspected';
            }
        }

        if ($kItem['status'] !== $newStatus) {
            $stmtUpd = $pdo->prepare("UPDATE kanban_items SET status = :st, updated_at = NOW() WHERE id = :kid");
            $stmtUpd->execute([':st' => $newStatus, ':kid' => $kid]);
        }

        return $newStatus;
    } catch (PDOException $e) {
        return null;
    }
}

/**
 * Automatically create Safety Stock overflow entry when a Kanban session passes with excess_qty > 0
 */
function createAutoSafetyStockForSession($pdo, $sessionId) {
    $sid = (int)$sessionId;
    if (!$pdo || $sid <= 0) return null;

    try {
        $stmtSessEx = $pdo->prepare("SELECT s.excess_qty, s.kanban_item_id, s.part_id, did.part_code, did.part_name, b.id as batch_id
                                     FROM inspection_sessions s
                                     JOIN daily_inspection_data did ON did.id = s.did_id
                                     LEFT JOIN kanban_items k ON k.id = s.kanban_item_id
                                     LEFT JOIN kanban_batches b ON b.id = k.batch_id
                                     WHERE s.id = :sid LIMIT 1");
        $stmtSessEx->execute([':sid' => $sid]);
        $sessEx = $stmtSessEx->fetch(PDO::FETCH_ASSOC);

        if ($sessEx && (int)$sessEx['excess_qty'] > 0) {
            $excessQty = (int)$sessEx['excess_qty'];

            $checkRef = "AUTO-SS-SESS-" . $sid;
            $stmtChkSS = $pdo->prepare("SELECT id FROM kanban_items WHERE remark LIKE :chk LIMIT 1");
            $stmtChkSS->execute([':chk' => '%' . $checkRef . '%']);
            $existingSSItem = $stmtChkSS->fetch(PDO::FETCH_ASSOC);

            if (!$existingSSItem) {
                $stmtLastLot = $pdo->prepare("SELECT lot_number, ref_number, scanned_qr_raw FROM inspection_session_lots WHERE inspection_session_id = :sid ORDER BY id DESC LIMIT 1");
                $stmtLastLot->execute([':sid' => $sid]);
                $lastLotRow = $stmtLastLot->fetch(PDO::FETCH_ASSOC);

                $lastLotNumber = $lastLotRow['lot_number'] ?? 'LOT-OVERFLOW';
                $lastRefNumber = $lastLotRow['ref_number'] ?? null;
                $lastQrRaw     = $lastLotRow['scanned_qr_raw'] ?? null;

                $batchId = (int)($sessEx['batch_id'] ?? 0);
                if ($batchId <= 0) {
                    $batchId = (int)$pdo->query("SELECT id FROM kanban_batches WHERE plan_type = 'safety_stock' ORDER BY id ASC LIMIT 1")->fetchColumn();
                }
                if ($batchId <= 0) {
                    $stmtInsBatch = $pdo->prepare("INSERT INTO kanban_batches (plan_type, vendor, document_number, import_method, imported_at) VALUES ('safety_stock', 'Safety Stock Storage', 'DOC-SAFETY-STOCK', 'manual', NOW())");
                    $stmtInsBatch->execute();
                    $batchId = (int)$pdo->lastInsertId();
                }
                $pCode   = $sessEx['part_code'];
                $pName   = $sessEx['part_name'];
                $partId  = $sessEx['part_id'] ?: null;

                // 1. Create kanban_items entry for Safety Stock
                $stmtInsSS = $pdo->prepare("INSERT INTO kanban_items 
                    (batch_id, plan_type, kanban_no, item_code, item_description, customer, req_date, qty, str_loc, supply_area, check_type, remark, created_at) 
                    VALUES (:bid, 'safety_stock', :kno, :code, :desc, 'INTERNAL SAFETY STOCK', NOW(), :qty, 'WH-SS-OVERFLOW', 'SAFETY STOCK WAREHOUSE', 'Safety Stock', :rem, NOW())");
                $stmtInsSS->execute([
                    ':bid'  => $batchId,
                    ':kno'  => 'SS-' . $lastLotNumber,
                    ':code' => $pCode,
                    ':desc' => $pName,
                    ':qty'  => $excessQty,
                    ':rem'  => "Auto Safety Stock Sisa Excess Kanban (Sisa Qty: {$excessQty} pcs, Lot: {$lastLotNumber}" . ($lastRefNumber ? ", Ref No: {$lastRefNumber}" : "") . ") [Ref: {$checkRef}]"
                ]);
                $ssKanbanItemId = (int)$pdo->lastInsertId();

                // 2. Ensure DID record exists for Safety Stock
                $stmtChkDid = $pdo->prepare("SELECT id FROM daily_inspection_data WHERE UPPER(part_code) = UPPER(:pcode) AND UPPER(lot_number) = UPPER(:lot) ORDER BY id DESC LIMIT 1");
                $stmtChkDid->execute([':pcode' => $pCode, ':lot' => $lastLotNumber]);
                $ssDidId = (int)$stmtChkDid->fetchColumn();

                if ($ssDidId === 0) {
                    $stmtInsDid = $pdo->prepare("INSERT INTO daily_inspection_data (part_code, part_name, lot_number, cavity, inspecting_date, status_inspect, pic, created_at) VALUES (:pcode, :pname, :lot, '1', CURDATE(), 'OK', 'SAFETY_STOCK_OVERFLOW', NOW())");
                    $stmtInsDid->execute([':pcode' => $pCode, ':pname' => $pName, ':lot' => $lastLotNumber]);
                    $ssDidId = (int)$pdo->lastInsertId();
                }

                // 3. Auto-create PASSED inspection_sessions for Safety Stock
                $currUser = function_exists('current_user') ? current_user() : null;
                $curUserId = (int)($currUser['id'] ?? ($_SESSION['user_id'] ?? 1));

                $stmtInsSSSession = $pdo->prepare("INSERT INTO inspection_sessions 
                    (inspection_type, did_id, kanban_item_id, part_id, inspector_id, sample_size, total_scanned_qty, excess_qty, reject_number, samples_checked, ng_count, status, started_at, closed_at) 
                    VALUES ('safety_stock', :did, :kanban, :part, :insp, 1, :tqty, 0, 1, 1, 0, 'passed', NOW(), NOW())");
                $stmtInsSSSession->execute([
                    ':did'    => $ssDidId,
                    ':kanban' => $ssKanbanItemId,
                    ':part'   => $partId,
                    ':insp'   => $curUserId,
                    ':tqty'   => $excessQty
                ]);
                $ssSessionId = (int)$pdo->lastInsertId();

                // 4. Create inspection_session_lots entry for Safety Stock session
                $stmtInsSSLot = $pdo->prepare("INSERT INTO inspection_session_lots 
                    (inspection_session_id, ref_number, lot_number, qty, scanned_qr_raw, remarks, lot_status, lot_result, closed_at, created_at) 
                    VALUES (:sid, :ref, :lot, :qty, :raw, :rem, 'ok', 'passed', NOW(), NOW())");
                $stmtInsSSLot->execute([
                    ':sid' => $ssSessionId,
                    ':ref' => $lastRefNumber,
                    ':lot' => $lastLotNumber,
                    ':qty' => $excessQty,
                    ':raw' => $lastQrRaw,
                    ':rem' => "Sisa Kelebihan Kanban dari Sesi #" . $sid
                ]);

                if ($ssSessionId > 0 && function_exists('syncDailySummaryForSession')) {
                    syncDailySummaryForSession($pdo, $ssSessionId);
                }

                return $ssSessionId;
            }
        }
    } catch (Exception $e) {
        error_log("Error creating auto Safety Stock: " . $e->getMessage());
    }
    return null;
}

/**
 * Helper to locate folder starting with [partCode] inside a directory (case-insensitive, prefix matching)
 */
function find_part_folder_in_dir($parentDir, $partCode) {
    if (!is_dir($parentDir)) return null;
    $folders = glob($parentDir . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR);
    if (!$folders) return null;

    $pCodeLower = strtolower(trim((string)$partCode));
    if (!$pCodeLower) return null;

    foreach ($folders as $dir) {
        $folderNameLower = strtolower(basename($dir));
        // Check if folder starts with partcode (e.g. "154176200_HOUSING,PANEL" starts with "154176200")
        if (strpos($folderNameLower, $pCodeLower) === 0) {
            return $dir;
        }
    }
    return null;
}

/**
 * Resolve 2D (PDF) and 3D (STP/STEP) drawings from filesystem based on folder convention:
 * uploads/drawings/[ModelName]/[PartCode]_[PartName]/[file].pdf and [file].stp
 * Uses smart prefix matching on PartCode to prevent failures from typos in PartName.
 * Falls back across all model folders if model name is empty or not matching.
 */
function get_part_drawing_assets($partCode, $modelName = '') {
    $result = [
        'drawing_2d_path' => null,
        'drawing_3d_path' => null,
        'drawing_2d_url'  => null,
        'drawing_3d_url'  => null,
        'folder_found'    => null,
    ];

    $cleanPartCode = trim((string)$partCode);
    if (!$cleanPartCode) return $result;

    $rootAppDir = realpath(__DIR__ . '/..');

    // Priority 1: Check unified directory uploads/parts/{PartCode}/
    $unifiedDir = $rootAppDir ? ($rootAppDir . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'parts' . DIRECTORY_SEPARATOR . $cleanPartCode) : null;
    if ($unifiedDir && is_dir($unifiedDir)) {
        $result['folder_found'] = $unifiedDir;
        $files = scandir($unifiedDir);
        if ($files) {
            foreach ($files as $f) {
                if ($f === '.' || $f === '..') continue;
                $fullPath = $unifiedDir . DIRECTORY_SEPARATOR . $f;
                if (!is_file($fullPath)) continue;

                $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
                $nameOnly = strtolower(pathinfo($f, PATHINFO_FILENAME));

                // 3D Model: STP or STEP
                if (($ext === 'stp' || $ext === 'step') && !$result['drawing_3d_path']) {
                    $relPath = 'uploads/parts/' . $cleanPartCode . '/' . $f;
                    $result['drawing_3d_path'] = $relPath;
                    $result['drawing_3d_url']  = base_url($relPath);
                }

                // 2D Drawing: PDF yang bukan dokumen QC
                if ($ext === 'pdf' && !$result['drawing_2d_path']) {
                    // Cek jika bukan pi, qp, pac, atau packing
                    $isQcDoc = preg_match('/(^|[_\-\s])(pi|process|qp|qpoint|q[_\-\s]?point|quality|pac|approval|cert|pack|packing)($|[_\-\s0-9])/i', $nameOnly);
                    if (!$isQcDoc) {
                        $relPath = 'uploads/parts/' . $cleanPartCode . '/' . $f;
                        $result['drawing_2d_path'] = $relPath;
                        $result['drawing_2d_url']  = base_url($relPath);
                    }
                }
            }
        }

        // Jika kedua drawing (atau salah satunya) sudah ditemukan di unified dir, kembalikan langsung
        if ($result['drawing_2d_path'] || $result['drawing_3d_path']) {
            return $result;
        }
    }

    $baseDir = realpath(__DIR__ . '/../uploads/drawings');
    if (!$baseDir || !is_dir($baseDir)) {
        return $result;
    }

    $cleanModel = trim((string)$modelName);
    $targetPartFolder = null;

    // 1. Try finding in specific model folder first if provided
    if ($cleanModel) {
        $modelDir = $baseDir . DIRECTORY_SEPARATOR . $cleanModel;
        if (is_dir($modelDir)) {
            $targetPartFolder = find_part_folder_in_dir($modelDir, $cleanPartCode);
        } else {
            // Case-insensitive model folder match
            $subdirs = glob($baseDir . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR);
            if ($subdirs) {
                foreach ($subdirs as $sd) {
                    if (strcasecmp(basename($sd), $cleanModel) === 0) {
                        $targetPartFolder = find_part_folder_in_dir($sd, $cleanPartCode);
                        if ($targetPartFolder) break;
                    }
                }
            }
        }
    }

    // 2. Fallback: Search across all model subfolders if not found
    if (!$targetPartFolder) {
        $allModelDirs = glob($baseDir . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR);
        if ($allModelDirs) {
            foreach ($allModelDirs as $mDir) {
                // Skip legacy 2d and 3d directories
                $bName = strtolower(basename($mDir));
                if ($bName === '2d' || $bName === '3d') continue;

                $found = find_part_folder_in_dir($mDir, $cleanPartCode);
                if ($found) {
                    $targetPartFolder = $found;
                    break;
                }
            }
        }
    }

    // 3. Fallback: Check if folder exists directly in root uploads/drawings/[PartCode]*
    if (!$targetPartFolder) {
        $targetPartFolder = find_part_folder_in_dir($baseDir, $cleanPartCode);
    }

    // 4. If target part folder is found, locate .pdf and .stp/.step files
    if ($targetPartFolder && is_dir($targetPartFolder)) {
        $result['folder_found'] = $targetPartFolder;

        $files = scandir($targetPartFolder);
        if ($files) {
            foreach ($files as $f) {
                if ($f === '.' || $f === '..') continue;
                $fullPath = $targetPartFolder . DIRECTORY_SEPARATOR . $f;
                if (!is_file($fullPath)) continue;

                $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));

                // 2D Drawing: PDF
                if ($ext === 'pdf' && !$result['drawing_2d_path']) {
                    $relPath = str_replace($rootAppDir . DIRECTORY_SEPARATOR, '', $fullPath);
                    $relPath = str_replace('\\', '/', $relPath);
                    $result['drawing_2d_path'] = $relPath;
                    $result['drawing_2d_url']  = base_url($relPath);
                }

                // 3D Model: STP or STEP
                if (($ext === 'stp' || $ext === 'step') && !$result['drawing_3d_path']) {
                    $relPath = str_replace($rootAppDir . DIRECTORY_SEPARATOR, '', $fullPath);
                    $relPath = str_replace('\\', '/', $relPath);
                    $result['drawing_3d_path'] = $relPath;
                    $result['drawing_3d_url']  = base_url($relPath);
                }
            }
        }
    }

    return $result;
}

/**
 * Helper to parse full QR code scan strings:
 * Format: Z1<part_code>|Z2<lot>|Z3<qty>|Z4<line>|Z5<ref_no>
 */
if (!function_exists('parseQrScanString')) {
    function parseQrScanString($raw) {
        $res = [
            'Z1' => '', // Part Code
            'Z2' => '', // Lot Number
            'Z3' => '', // Qty / Cavity
            'Z4' => '', // Line
            'Z5' => '', // Ref Number / DID Number
            'is_qr' => false
        ];
        if (empty($raw)) return $res;
        $raw = trim($raw);
        if (strpos($raw, '|') !== false || strpos($raw, 'Z1') !== false || strpos($raw, 'Z2') !== false || strpos($raw, 'Z5') !== false) {
            $res['is_qr'] = true;
            $parts = explode('|', $raw);
            foreach ($parts as $p) {
                $p = trim($p);
                if (strpos($p, 'Z1') === 0) $res['Z1'] = trim(substr($p, 2));
                elseif (strpos($p, 'Z2') === 0) $res['Z2'] = trim(substr($p, 2));
                elseif (strpos($p, 'Z3') === 0) $res['Z3'] = trim(substr($p, 2));
                elseif (strpos($p, 'Z4') === 0) $res['Z4'] = trim(substr($p, 2));
                elseif (strpos($p, 'Z5') === 0) $res['Z5'] = trim(substr($p, 2));
            }
        }
        return $res;
    }
}

/**
 * Get count of active OPEN lots in Quarantine (matching modules/ng_quarantine/index.php)
 */
if (!function_exists('getOpenQuarantineLotsCount')) {
    function getOpenQuarantineLotsCount($pdo = null) {
        if (!$pdo) {
            if (!function_exists('getDB')) {
                require_once __DIR__ . '/database.php';
            }
            $pdo = getDB();
        }
        if (!$pdo) return 0;

        try {
            // Fetch raw NG lots
            $allNgLots = $pdo->query("
                SELECT 
                    isl.id AS lot_id,
                    isl.lot_number,
                    isl.ref_number,
                    isl.lot_status,
                    k.item_code AS part_code
                FROM inspection_session_lots isl
                JOIN inspection_sessions s ON s.id = isl.inspection_session_id
                JOIN kanban_items k ON k.id = s.kanban_item_id
                WHERE (
                    isl.lot_result = 'rejected' 
                    OR isl.lot_status IN ('rejected', 'ng_quarantine')
                    OR EXISTS (
                        SELECT 1 FROM inspection_ng_records ngr 
                        WHERE ngr.session_lot_id = isl.id 
                          AND (ngr.is_cancelled IS NULL OR ngr.is_cancelled = 0)
                    )
                )
                AND (isl.ref_number IS NULL OR (isl.ref_number != 'DIRECT-PASS' AND isl.ref_number NOT LIKE '%DIRECT-PASS%'))
                AND (s.approval_notes IS NULL OR s.approval_notes NOT LIKE '%Direct Close%')
                ORDER BY isl.id DESC
            ")->fetchAll(PDO::FETCH_ASSOC);

            if (empty($allNgLots)) return 0;

            $ngGroupKeys = [];
            $refNumbers = [];
            $unreferencedNgLots = [];

            foreach ($allNgLots as $row) {
                $pCode = strtoupper(trim($row['part_code'] ?? ''));
                $lNum  = strtoupper(trim($row['lot_number'] ?? ''));
                $rNum  = strtoupper(trim($row['ref_number'] ?? ''));
                $groupKey = $pCode . '|' . $lNum . '|' . $rNum;
                $ngGroupKeys[$groupKey] = true;

                if (!empty($rNum)) {
                    $refNumbers[] = $row['ref_number'];
                } else {
                    $unreferencedNgLots[] = ['part_code' => $row['part_code'], 'lot_number' => $row['lot_number']];
                }
            }

            $refNumbers = array_filter(array_unique($refNumbers));
            $allSessionsForLots = $allNgLots;

            if (!empty($refNumbers) || !empty($unreferencedNgLots)) {
                $whereParts = [];
                $params = [];
                if (!empty($refNumbers)) {
                    $pRef = implode(',', array_fill(0, count($refNumbers), '?'));
                    $whereParts[] = "isl.ref_number IN ($pRef)";
                    foreach ($refNumbers as $r) $params[] = $r;
                }
                foreach ($unreferencedNgLots as $uLot) {
                    $whereParts[] = "(k.item_code = ? AND isl.lot_number = ? AND (isl.ref_number IS NULL OR isl.ref_number = ''))";
                    $params[] = $uLot['part_code'];
                    $params[] = $uLot['lot_number'];
                }
                $whereRelStr = "(" . implode(" OR ", $whereParts) . ")";
                $sqlAllRounds = "
                    SELECT isl.id AS lot_id, isl.lot_number, isl.ref_number, isl.lot_status, k.item_code AS part_code
                    FROM inspection_session_lots isl
                    JOIN inspection_sessions s ON s.id = isl.inspection_session_id
                    JOIN kanban_items k ON k.id = s.kanban_item_id
                    WHERE {$whereRelStr}
                      AND (isl.ref_number IS NULL OR (isl.ref_number != 'DIRECT-PASS' AND isl.ref_number NOT LIKE '%DIRECT-PASS%'))
                      AND (s.approval_notes IS NULL OR s.approval_notes NOT LIKE '%Direct Close%')
                    ORDER BY isl.id DESC
                ";
                $stmtRounds = $pdo->prepare($sqlAllRounds);
                $stmtRounds->execute($params);
                $fetched = $stmtRounds->fetchAll(PDO::FETCH_ASSOC);
                if (!empty($fetched)) $allSessionsForLots = $fetched;
            }

            $allLotIds = array_column($allSessionsForLots, 'lot_id');
            $closuresByLot = [];
            if (!empty($allLotIds)) {
                $inLotIds = implode(',', array_map('intval', array_unique($allLotIds)));
                $closureSql = "SELECT session_lot_id, closure_type FROM lot_quarantine_closures WHERE session_lot_id IN ($inLotIds)";
                $stmtClosure = $pdo->query($closureSql);
                while ($cRow = $stmtClosure->fetch(PDO::FETCH_ASSOC)) {
                    $closuresByLot[(int)$cRow['session_lot_id']] = $cRow;
                }
            }

            $groupedNgLotsMap = [];
            foreach ($allSessionsForLots as $row) {
                $pCode = strtoupper(trim($row['part_code'] ?? ''));
                $lNum  = strtoupper(trim($row['lot_number'] ?? ''));
                $rNum  = strtoupper(trim($row['ref_number'] ?? ''));
                $groupKey = $pCode . '|' . $lNum . '|' . $rNum;
                if (!isset($ngGroupKeys[$groupKey])) continue;
                $groupedNgLotsMap[$groupKey][] = $row;
            }

            $openCount = 0;
            foreach ($groupedNgLotsMap as $groupKey => $groupRows) {
                $latestRow = $groupRows[0];
                $groupClosure = null;
                foreach ($groupRows as $gRow) {
                    $gLotId = (int)$gRow['lot_id'];
                    if (isset($closuresByLot[$gLotId])) {
                        $groupClosure = $closuresByLot[$gLotId];
                        break;
                    }
                }
                $lotSt = strtolower($latestRow['lot_status'] ?? '');
                $isClosed = ($groupClosure !== null || in_array($lotSt, ['abolished', 'action_closed'], true));
                if (!$isClosed) {
                    $openCount++;
                }
            }

            return $openCount;
        } catch (Exception $e) {
            return 0;
        }
    }
}

// Include OQC Summary Aggregate Helper
require_once __DIR__ . '/summary_helper.php';


