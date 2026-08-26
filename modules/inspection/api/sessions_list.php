<?php
/**
 * API: Sessions List (untuk SPA View List)
 * Mengembalikan daftar inspection_sessions dalam format JSON
 * Params: search, status, start_date, end_date, page, limit
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/helper.php';

$pdo = getDB();
if (!$pdo) {
    echo json_encode(['success' => false, 'message' => 'DB connection failed']);
    exit;
}

$search      = trim(sanitize($_GET['search'] ?? ''));
$statusFilter = trim(sanitize($_GET['status'] ?? 'all'));
$startDate   = trim(sanitize($_GET['start_date'] ?? date('Y-m-01')));
$endDate     = trim(sanitize($_GET['end_date'] ?? date('Y-m-d')));
$page        = max(1, (int)($_GET['page'] ?? 1));
$limit       = min(100, max(1, (int)($_GET['limit'] ?? 20)));

try {
    $where  = ['1=1'];
    $params = [];

    if ($search !== '') {
        $where[]      = '(did.part_code LIKE :s1 OR did.part_name LIKE :s2 OR did.lot_number LIKE :s3 OR k.customer LIKE :s4)';
        $params[':s1'] = '%' . $search . '%';
        $params[':s2'] = '%' . $search . '%';
        $params[':s3'] = '%' . $search . '%';
        $params[':s4'] = '%' . $search . '%';
    }

    if ($statusFilter !== 'all') {
        $where[]      = 's.status = :st';
        $params[':st'] = $statusFilter;
    }

    if (!empty($startDate)) {
        $where[]             = 'DATE(s.started_at) >= :start_date';
        $params[':start_date'] = $startDate;
    }

    if (!empty($endDate)) {
        $where[]           = 'DATE(s.started_at) <= :end_date';
        $params[':end_date'] = $endDate;
    }

    $whereClause = implode(' AND ', $where);

    // Count
    $countSql = "SELECT COUNT(DISTINCT s.id)
                 FROM inspection_sessions s
                 JOIN daily_inspection_data did ON did.id = s.did_id
                 LEFT JOIN kanban_items k ON k.id = s.kanban_item_id
                 WHERE {$whereClause}";
    $stmtCount = $pdo->prepare($countSql);
    $stmtCount->execute($params);
    $totalItems = (int)$stmtCount->fetchColumn();
    $totalPages = max(1, (int)ceil($totalItems / $limit));
    if ($page > $totalPages) $page = $totalPages;
    $offset = ($page - 1) * $limit;

    // Fetch
    $sql = "SELECT s.id, s.status, s.samples_checked, s.sample_size, s.ng_count, s.reject_number,
                   s.started_at, s.inspection_type,
                   did.part_code, did.part_name, did.lot_number, did.cavity,
                   k.kanban_no, k.customer
            FROM inspection_sessions s
            JOIN daily_inspection_data did ON did.id = s.did_id
            LEFT JOIN kanban_items k ON k.id = s.kanban_item_id
            WHERE {$whereClause}
            ORDER BY s.id DESC
            LIMIT {$limit} OFFSET {$offset}";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success'     => true,
        'sessions'    => $sessions,
        'pagination'  => [
            'page'       => $page,
            'limit'      => $limit,
            'total_items' => $totalItems,
            'total_pages' => $totalPages,
        ],
        'filters' => [
            'search'     => $search,
            'status'     => $statusFilter,
            'start_date' => $startDate,
            'end_date'   => $endDate,
        ]
    ]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'DB error: ' . $e->getMessage()]);
}
