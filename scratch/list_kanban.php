<?php
require 'config/database.php';
$stmt = $pdo->query('SELECT id, kanban_no, qty, status FROM kanban_items ORDER BY id DESC LIMIT 10');
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
