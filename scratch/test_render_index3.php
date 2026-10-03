<?php
if (session_status() === PHP_SESSION_NONE) session_start();
$_SESSION['user_id'] = 1;
$_SESSION['user_role'] = 'admin';
$_SESSION['user_name'] = 'Admin';
$_GET['period'] = 'today';

ob_start();
require __DIR__ . '/../modules/inspection/index.php';
$html = ob_get_clean();

$pos = strpos($html, 'Target: <b class="text-slate-800">500 pcs</b>');
if ($pos !== false) {
    echo substr($html, $pos + 1200, 2000);
}
