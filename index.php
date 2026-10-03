<?php
/**
 * Root Entry Point - Redirects to First Accessible Module for User
 */
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/helper.php';

require_login();

$target = get_first_accessible_url() ?: 'modules/dashboard/index.php';
redirect($target);
