<?php
/**
 * Helper Utility Functions
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Generate full URL from relative path
 */
function base_url($path = '') {
    $cleanPath = ltrim($path, '/');
    return BASE_URL . ($cleanPath ? '/' . $cleanPath : '');
}

/**
 * Sanitize user input strings
 */
function sanitize($data) {
    if (is_array($data)) {
        return array_map('sanitize', $data);
    }
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

/**
 * Perform URL redirection
 */
function redirect($path) {
    $target = (strpos($path, 'http://') === 0 || strpos($path, 'https://') === 0) ? $path : base_url($path);
    header("Location: " . $target);
    exit;
}

/**
 * Set Session Flash Message (Success, Error, Warning, Info)
 */
function set_flash($type, $message) {
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message
    ];
}

/**
 * Retrieve & clear Session Flash Message
 */
function get_flash() {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

/**
 * Render Flash Alert HTML badge
 */
function render_flash() {
    $flash = get_flash();
    if (!$flash) return '';

    $type = $flash['type'];
    $msg = $flash['message'];

    $colorClasses = [
        'success' => 'bg-emerald-50 text-emerald-800 border-emerald-200',
        'error'   => 'bg-rose-50 text-rose-800 border-rose-200',
        'warning' => 'bg-amber-50 text-amber-800 border-amber-200',
        'info'    => 'bg-sky-50 text-sky-800 border-sky-200',
    ];

    $class = $colorClasses[$type] ?? $colorClasses['info'];

    return '
    <div class="mb-4 p-3 rounded-xl border flex items-center justify-between shadow-xs transition-all duration-300 ' . $class . '" id="flash-alert">
        <div class="flex items-center space-x-2">
            <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
            <span class="text-xs font-semibold">' . htmlspecialchars($msg) . '</span>
        </div>
        <button type="button" onclick="document.getElementById(\'flash-alert\').remove()" class="text-current opacity-70 hover:opacity-100 p-1 rounded-lg">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
            </svg>
        </button>
    </div>';
}

/**
 * Check active menu route for active styling
 */
function is_active_menu($menuName) {
    $currentUri = $_SERVER['REQUEST_URI'] ?? '';
    return (strpos($currentUri, $menuName) !== false) ? true : false;
}

/**
 * Format Indonesia Date
 */
function format_date($dateString) {
    if (!$dateString) return '-';
    $time = strtotime($dateString);
    return date('d M Y, H:i', $time);
}

/**
 * Render Reusable HTML Pagination Bar (Always 1 Horizontal Line)
 */
function render_pagination($currentPage, $totalPages, $totalItems, $perPage = 10) {
    $start = $totalItems > 0 ? (($currentPage - 1) * $perPage) + 1 : 0;
    $end = min($currentPage * $perPage, $totalItems);

    // Build query params preserving search & filter
    $queryParams = $_GET;
    
    $buildUrl = function($page) use ($queryParams) {
        $queryParams['page'] = $page;
        return '?' . http_build_query($queryParams);
    };

    $html = '<div class="px-4 py-2.5 bg-white border-t border-slate-200/80 text-xs text-slate-500 font-medium" style="display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;">';
    
    // Left info
    $html .= '<div>';
    $html .= 'Menampilkan <span class="font-bold text-slate-800">' . $start . '</span> - <span class="font-bold text-slate-800">' . $end . '</span> dari <span class="font-bold text-slate-800">' . $totalItems . '</span> data';
    $html .= '</div>';

    // Right Controls (Force 1 Horizontal Line)
    $html .= '<div style="display: flex; align-items: center; gap: 4px;">';
    
    // Prev button
    if ($currentPage > 1) {
        $html .= '<a href="' . $buildUrl($currentPage - 1) . '" class="btn-secondary py-1 px-2.5 text-xs">&laquo; Prev</a>';
    } else {
        $html .= '<span class="px-2.5 py-1 text-slate-300 border border-slate-200 rounded-lg text-xs cursor-not-allowed select-none">&laquo; Prev</span>';
    }

    // Page Numbers
    $window = 2;
    $maxPages = max(1, $totalPages);
    $startPage = max(1, $currentPage - $window);
    $endPage = min($maxPages, $currentPage + $window);

    if ($startPage > 1) {
        $html .= '<a href="' . $buildUrl(1) . '" class="btn-secondary py-1 px-2.5 text-xs">1</a>';
        if ($startPage > 2) {
            $html .= '<span class="px-1.5 py-1 text-slate-400">...</span>';
        }
    }

    for ($p = $startPage; $p <= $endPage; $p++) {
        if ($p == $currentPage) {
            $html .= '<span class="bg-blue-600 text-white font-bold px-3 py-1 rounded-lg text-xs shadow-xs">' . $p . '</span>';
        } else {
            $html .= '<a href="' . $buildUrl($p) . '" class="btn-secondary py-1 px-2.5 text-xs">' . $p . '</a>';
        }
    }

    if ($endPage < $totalPages) {
        if ($endPage < $totalPages - 1) {
            $html .= '<span class="px-1.5 py-1 text-slate-400">...</span>';
        }
        $html .= '<a href="' . $buildUrl($totalPages) . '" class="btn-secondary py-1 px-2.5 text-xs">' . $totalPages . '</a>';
    }

    // Next button
    if ($currentPage < $totalPages) {
        $html .= '<a href="' . $buildUrl($currentPage + 1) . '" class="btn-secondary py-1 px-2.5 text-xs">Next &raquo;</a>';
    } else {
        $html .= '<span class="px-2.5 py-1 text-slate-300 border border-slate-200 rounded-lg text-xs cursor-not-allowed select-none">Next &raquo;</span>';
    }

    $html .= '</div>';
    $html .= '</div>';

    return $html;
}
