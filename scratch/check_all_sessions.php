<?php
require_once __DIR__ . '/../config/database.php';
$pdo = getDB();

$stmt = $pdo->query("SELECT * FROM inspection_session_lots WHERE inspection_session_id = 4");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));


