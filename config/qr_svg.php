<?php
/**
 * Standalone Pure-PHP QR Code SVG Generator
 * 100% Offline, ISO/IEC 18004 Standard Compliant, Zero External Dependencies
 * PT. Surya Technology Industri — OQC System
 */

require_once __DIR__ . '/qrcode_engine.php';

if (!function_exists('generate_qr_svg')) {
    /**
     * Generate standard-compliant SVG QR Code
     *
     * @param string $text Content/URL to encode
     * @param int $size Display size in pixels (width and height)
     * @param string $ecLevel Error correction level: 'L', 'M', 'Q', 'H' (default 'M')
     * @param int $margin Quiet zone margin in modules (minimum 4 per ISO/IEC 18004)
     * @return string Valid SVG XML string
     */
    function generate_qr_svg($text, $size = 44, $ecLevel = 'M', $margin = 4) {
        if (empty($text)) {
            $text = ' ';
        }

        $qr = new QRcode($text, $ecLevel);
        $arr = $qr->getBarcodeArray();

        $numRows = $arr['num_rows'];
        $numCols = $arr['num_cols'];
        $bcode = $arr['bcode'];

        $totalWidth = $numCols + (2 * $margin);
        $totalHeight = $numRows + (2 * $margin);

        $path = '';
        for ($r = 0; $r < $numRows; $r++) {
            $row = $bcode[$r];
            for ($c = 0; $c < $numCols; $c++) {
                if ($row[$c] == 1) {
                    $x = $c + $margin;
                    $y = $r + $margin;
                    $path .= "M{$x},{$y}h1v1h-1z ";
                }
            }
        }

        $svg = "<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 {$totalWidth} {$totalHeight}' width='{$size}' height='{$size}' shape-rendering='crispEdges' style='display:inline-block; vertical-align:middle; flex-shrink:0;'>";
        $svg .= "<rect width='{$totalWidth}' height='{$totalHeight}' fill='#ffffff'/>";
        $svg .= "<path d='" . trim($path) . "' fill='#000000'/>";
        $svg .= "</svg>";

        return $svg;
    }
}
