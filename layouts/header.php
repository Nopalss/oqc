<?php
// Load Required Application Configurations & Helpers
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helper.php';

// ── Global Auth Guard: semua halaman yang include header ini wajib login ──────
require_login();

$pageTitle = $pageTitle ?? APP_NAME;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> | <?= APP_NAME ?></title>
    
    <!-- Favicon SVG Data URI -->
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect width='100' height='100' rx='20' fill='%232563eb'/><text x='50%' y='68%' font-size='50' font-weight='bold' fill='white' text-anchor='middle'>ST</text></svg>">

    <!-- Offline Compiled Tailwind CSS -->
    <link rel="stylesheet" href="<?= base_url('assets/css/tailwind.css') ?>">
    <meta name="base-url" content="<?= base_url() ?>">
    <script>window.APP_BASE_URL = "<?= rtrim(base_url(), '/') . '/' ?>";</script>
    
    <!-- Offline Core Libraries (Available globally for all inline scripts) -->
    <script src="<?= base_url('assets/js/vendor/jquery.min.js') ?>"></script>
    <script src="<?= base_url('assets/js/vendor/sweetalert2.all.min.js') ?>"></script>
    <script src="<?= base_url('assets/js/app.js?v=' . filemtime(__DIR__ . '/../assets/js/app.js')) ?>"></script>
</head>
<body class="bg-slate-50 text-slate-800 antialiased min-h-screen">

<!-- Mobile Sidebar Backdrop Overlay -->
<div id="sidebar-overlay"></div>

<div class="flex min-h-screen">
