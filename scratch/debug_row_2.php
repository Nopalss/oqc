<?php
require_once __DIR__ . '/../config/database.php';
$stmt = $pdo->query("SELECT * FROM inspection_sessions WHERE id = 2");
print_r($stmt->fetch(PDO::FETCH_ASSOC));
