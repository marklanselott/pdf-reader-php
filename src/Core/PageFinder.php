<?php
namespace ParserPDF\Core;

use ParserPDF\Util\Log;

class PageFinder
{
    public static function find(Context $ctx): void
    {
        foreach ($ctx->objects as $num => $body) {
            if (strpos($body, '/Type/Page') !== false) {
                $ctx->pages[] = $num;
            }
        }
        Log::d($ctx, "Найдено страниц: " . count($ctx->pages));

        // MediaBox
        foreach ($ctx->pages as $i => $objNum) {
            $body = $ctx->objects[$objNum] ?? '';
            $w = 595.32; $h = 841.92;
            if (preg_match('/\/MediaBox\s*\[\s*([0-9.\-]+)\s+([0-9.\-]+)\s+([0-9.\-]+)\s+([0-9.\-]+)\s*\]/', $body, $mm)) {
                $x1 = (float)$mm[1];
                $y1 = (float)$mm[2];
                $x2 = (float)$mm[3];
                $y2 = (float)$mm[4];
                $w = max(1.0, $x2 - $x1);
                $h = max(1.0, $y2 - $y1);
            }
            $ctx->pageBoxes[$i+1] = ['w'=>$w,'h'=>$h];
        }
    }
}