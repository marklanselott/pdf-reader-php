<?php
namespace ParserPDF\Util;
class Log {
    public static function d($ctxOrNull, string $msg){
        fwrite(STDERR, $msg.PHP_EOL);
    }
}