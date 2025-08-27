<?php
namespace ParserPDF\Core;

use RuntimeException;
use ParserPDF\Util\Log;

class PdfLoader
{
    public static function load(Context $ctx): void
    {
        $data = @file_get_contents($ctx->filePath);
        if ($data === false) {
            throw new RuntimeException("Не удалось прочитать файл {$ctx->filePath}");
        }
        $ctx->rawData = $data;
        Log::d($ctx, "Загружен файл, размер " . strlen($data));
    }
}