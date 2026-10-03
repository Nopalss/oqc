<?php
require 'config/database.php';
$stmt = $pdo->query('SELECT s.id, s.kanban_item_id, s.status FROM inspection_sessions s ORDER BY s.id DESC');
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
