<?php
/**
 * AJAX Helper: Ambil data spesifik Item / Grup / Tahap untuk pre-fill Modal Edit
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$pdo = getDB();
if (!$pdo) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
    exit;
}

$type = sanitize($_GET['type'] ?? '');
$id   = (int)($_GET['id'] ?? 0);

if ($id <= 0 || !in_array($type, ['item', 'group', 'phase'])) {
    echo json_encode(['success' => false, 'message' => 'Parameter tidak valid']);
    exit;
}

try {
    if ($type === 'item') {
        $stmt = $pdo->prepare("SELECT i.*, g.title as group_title, g.phase_id 
                               FROM oqc_monitoring_items i 
                               JOIN oqc_monitoring_groups g ON i.group_id = g.id 
                               WHERE i.id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            echo json_encode(['success' => false, 'message' => 'Item tidak ditemukan']);
            exit;
        }
        echo json_encode(['success' => true, 'data' => $row]);
        exit;

    } elseif ($type === 'group') {
        $stmt = $pdo->prepare("SELECT g.*, p.name as phase_name 
                               FROM oqc_monitoring_groups g 
                               JOIN oqc_monitoring_phases p ON g.phase_id = p.id 
                               WHERE g.id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            echo json_encode(['success' => false, 'message' => 'Kategori tidak ditemukan']);
            exit;
        }
        echo json_encode(['success' => true, 'data' => $row]);
        exit;

    } elseif ($type === 'phase') {
        $stmt = $pdo->prepare("SELECT * FROM oqc_monitoring_phases WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            echo json_encode(['success' => false, 'message' => 'Tahap tidak ditemukan']);
            exit;
        }
        echo json_encode(['success' => true, 'data' => $row]);
        exit;
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
}

