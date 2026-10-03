<?php
if (session_status() === PHP_SESSION_NONE) session_start();
$_SESSION['user_id'] = 1;
$_SESSION['user_role'] = 'admin';
$_SESSION['user_name'] = 'Admin';
$_GET['period'] = 'today';

ob_start();
require __DIR__ . '/../modules/inspection/index.php';
$html = ob_get_clean();

$pos = strpos($html, 'text-right">Aksi</th>');
if ($pos !== false) {
    echo substr($html, $pos, 2500);
}
