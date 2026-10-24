<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helper.php';
$pdo = getDB();

syncKanbanStatus($pdo, 3);
syncKanbanStatus($pdo, 2);

$stmt = $pdo->query("SELECT id, kanban_no, qty, status FROM kanban_items WHERE id IN (2, 3)");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
