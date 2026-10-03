<?php
require 'config/database.php';
$stmt = $pdo->query('SELECT id, username, role FROM users LIMIT 5');
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
