<?php
namespace ParserPDF\Util;

final class Geo
{
    public static function iou(array $a, array $b): float
    {
        $ix1 = max($a['x1'], $b['x1']);
        $iy1 = max($a['y1'], $b['y1']);
        $ix2 = min($a['x2'], $b['x2']);
        $iy2 = min($a['y2'], $b['y2']);
        $iw  = max(0, $ix2 - $ix1);
        $ih  = max(0, $iy2 - $iy1);
        $inter = $iw * $ih;
        $areaA = ($a['x2'] - $a['x1']) * ($a['y2'] - $a['y1']);
        $areaB = ($b['x2'] - $b['x1']) * ($b['y2'] - $b['y1']);
        if ($areaA <= 0 || $areaB <= 0) return 0;
        return $inter / ($areaA + $areaB - $inter + 1e-9);
    }
}