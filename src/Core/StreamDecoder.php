<?php
namespace ParserPDF\Core;

class StreamDecoder
{
    public static function getDecodedStream(Context $ctx, int $objNum): ?string
    {
        $body = $ctx->objects[$objNum] ?? null;
        if ($body === null) return null;
        if (!preg_match('/stream\r?\n(.*?)endstream/s', $body, $m)) return null;
        $raw = rtrim($m[1], "\r\n");

        foreach (['gzuncompress','gzdecode','gzinflate'] as $fn) {
            if (function_exists($fn)) {
                $out = @$fn($raw);
                if ($out !== false) return $out;
            }
        }
        if (strpos($raw,'BT')!==false) return $raw;
        return null;
    }
}