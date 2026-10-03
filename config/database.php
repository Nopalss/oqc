<?php
/**
 * Database Connection Management (PDO Prepared Statements)
 * Optimized for High-Concurrency & Zero DDL Runtime Overhead
 */

require_once __DIR__ . '/env.php';

$db_host = env('DB_HOST', 'localhost');
$db_user = env('DB_USER', 'root');
$db_pass = env('DB_PASS', '');
$db_name = env('DB_NAME', 'oqc_db');
$db_port = (int) env('DB_PORT', 3306);

try {
    global $pdo, $db_error;
    $dsn = "mysql:host={$db_host};port={$db_port};dbname={$db_name};charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
    ];
    
    $pdo = new PDO($dsn, $db_user, $db_pass, $options);
} catch (PDOException $e) {
    $pdo = null;
    $db_error = $e->getMessage();
}

/**
 * Get active PDO instance
 * 
 * @return PDO|null
 */
function getDB() {
    global $pdo;
    return $pdo;
}
