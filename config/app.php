<?php

/**
 * Application Configuration & Environment Settings
 */

// Basic Application Metadata
define('APP_NAME', 'OQC Core System');
define('APP_VERSION', '1.0.0');

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
