<?php
require_once __DIR__ . '/../config/database.php';
$stmt = $pdo->query("SELECT * FROM aql_standards WHERE qty_min <= 500 ORDER BY qty_min ASC, inspection_level ASC");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
