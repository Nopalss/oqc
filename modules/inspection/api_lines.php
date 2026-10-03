<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = getDB();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'POST') {
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if (empty($name)) {
            echo json_encode([
                'success' => false,
                'message' => 'Nama Line tidak boleh kosong.'
            ]);
            exit;
        }

        // Cek apakah line sudah ada (case-insensitive)
        $stmtChk = $pdo->prepare("SELECT id, name FROM `lines` WHERE LOWER(name) = LOWER(?) LIMIT 1");
        $stmtChk->execute([$name]);
        $existing = $stmtChk->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $selectedId = (int)$existing['id'];
            $selectedName = $existing['name'];
        } else {
            $stmtIns = $pdo->prepare("INSERT INTO `lines` (name, description, status) VALUES (?, ?, 'active')");
            $stmtIns->execute([$name, $description ?: 'Custom Line']);
            $selectedId = (int)$pdo->lastInsertId();
            $selectedName = $name;
        }

        // Ambil list terbaru
        $stmtAll = $pdo->query("SELECT id, name, description FROM `lines` WHERE status = 'active' ORDER BY name ASC");
        $allLines = $stmtAll->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success'   => true,
            'message'   => 'Line berhasil disimpan.',
            'line_id'   => $selectedId,
            'line_name' => $selectedName,
            'selected'  => $selectedName,
            'lines'     => $allLines
        ]);
        exit;
    }

    // Default GET: List semua active lines
    $stmt = $pdo->query("SELECT id, name, description FROM `lines` WHERE status = 'active' ORDER BY name ASC");
    $lines = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'lines' => $lines
    ]);
    exit;

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
    exit;
}
