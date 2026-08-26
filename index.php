<?php
/**
 * Root Entry Point - Redirects to Dashboard Module
 */
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/helper.php';

redirect('modules/dashboard/index.php');
