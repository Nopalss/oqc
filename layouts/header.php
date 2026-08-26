<?php
// Load Required Application Configurations & Helpers
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helper.php';

$pageTitle = $pageTitle ?? APP_NAME;
?>
<!DOCTYPE html>
<html lang="id" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> | <?= APP_NAME ?></title>
    
    <!-- Favicon SVG Data URI -->
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect width='100' height='100' rx='20' fill='%232563eb'/><text x='50%' y='68%' font-size='50' font-weight='bold' fill='white' text-anchor='middle'>ST</text></svg>">

    <!-- Offline Compiled Tailwind CSS -->
    <link rel="stylesheet" href="<?= base_url('assets/css/tailwind.css') ?>">
</head>
<body class="bg-slate-50 text-slate-800 antialiased h-full flex flex-col">

<!-- Mobile Sidebar Backdrop Overlay -->
<div id="sidebar-overlay"></div>

<div class="flex min-h-screen">
