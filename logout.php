<?php
/**
 * Logout Handler
 */
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/helper.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Clear user session variables
unset($_SESSION['user_id']);
unset($_SESSION['user_name']);
unset($_SESSION['username']);
unset($_SESSION['user_role']);

set_flash('info', 'Anda telah berhasil keluar dari sistem OQC.');
redirect('login.php');
