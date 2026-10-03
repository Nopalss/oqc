<?php
/**
 * Logout Handler - OQC System
 */
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/helper.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Hapus semua data sesi
$_SESSION = [];

// Hapus session cookie dari browser
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

// Hancurkan sesi di server
session_destroy();

// Flash message perlu session baru
session_start();
set_flash('info', 'Anda telah berhasil keluar dari sistem OQC.');
session_write_close();

redirect('login.php');
