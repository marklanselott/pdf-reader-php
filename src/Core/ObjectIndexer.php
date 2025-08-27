<?php
namespace ParserPDF\Core;

use ParserPDF\Util\Log;

class ObjectIndexer
{
    public static function index(Context $ctx): void
    {
        if (preg_match_all('/\b(\d+)\s+\d+\s+obj(.*?endobj)/s', $ctx->rawData, $m, PREG_SET_ORDER)) {
            foreach ($m as $o) {
                $ctx->objects[(int)$o[1]] = $o[2];
            }
        }
        Log::d($ctx, "Индекс объектов: " . count($ctx->objects));
    }
}