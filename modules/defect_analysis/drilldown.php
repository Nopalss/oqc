<?php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/helper.php';

$qs = !empty($_SERVER['QUERY_STRING']) ? ('?' . $_SERVER['QUERY_STRING']) : '';
redirect('modules/defect_analysis/index.php' . $qs);
