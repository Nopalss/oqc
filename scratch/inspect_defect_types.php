<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

$pdo = getDB();
$stmt = $pdo->query("DESCRIBE defect_types");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
