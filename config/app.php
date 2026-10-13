<?php

/**
 * Application Configuration & Environment Settings
 */

require_once __DIR__ . '/env.php';

// Basic Application Metadata
define('APP_NAME', env('APP_NAME', 'OQC Core System'));
define('APP_VERSION', '1.0.0');
define('APP_ENV', env('APP_ENV', 'production'));
define('APP_DEBUG', (bool) env('APP_DEBUG', false));

// ── Secure Session Configuration (must be set before session_start) ──────────
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? 80) == 443);
if (session_status() === PHP_SESSION_NONE) {
    @ini_set('session.cookie_httponly', '1');
    @ini_set('session.cookie_secure', $isHttps ? '1' : '0');
    @ini_set('session.cookie_samesite', 'Lax');
    @ini_set('session.use_strict_mode', '1');
    @ini_set('session.gc_maxlifetime', '28800');
    @ini_set('session.cookie_lifetime', '0');
}

// Dynamic Base URL Auto-Detection (Supports Apache, Nginx, Laragon VirtualHosts & Subfolders)
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || ($_SERVER['SERVER_PORT'] ?? 80) == 443) ? "https://" : "http://";
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';

$scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');

// Check if running under /oqc subfolder or directly as DocumentRoot
if (strpos($scriptName, '/oqc/') === 0 || $scriptName === '/oqc') {
    $basePath = '/oqc';
} else {
    $basePath = '';
}

define('BASE_URL', rtrim($protocol . $host . $basePath, '/'));

// Timezone Setup
date_default_timezone_set('Asia/Jakarta');
