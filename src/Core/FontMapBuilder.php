<?php
namespace ParserPDF\Core;

class FontMapBuilder
{
    public static function buildPageFontMaps(Context $ctx, string $pageBody): array
    {
        $maps = [];
        if (preg_match('/\/Resources\s*(<<.*?>>)/s', $pageBody, $res)) {
            $resDict = $res[1];
            if (preg_match('/\/Font\s*(<<.*?>>)/s', $resDict, $fm)) {
                if (preg_match_all('/\/(F\d+)\s+(\d+)\s+\d+\s+R/', $fm[1], $refs, PREG_SET_ORDER)) {
                    foreach ($refs as $r) {
                        $maps[$r[1]] = self::getFontMap($ctx, (int)$r[2]);
                    }
                }
            }
        }
        return $maps;
    }

    private static function getFontMap(Context $ctx, int $fontObjNum): array
    {
        if (isset($ctx->fontMapsCache[$fontObjNum])) return $ctx->fontMapsCache[$fontObjNum];
        $body = $ctx->objects[$fontObjNum] ?? '';
        if (!preg_match('/\/ToUnicode\s+(\d+)\s+\d+\s+R/', $body, $m)) {
            return $ctx->fontMapsCache[$fontObjNum] = [];
        }
        $cmapObj = (int)$m[1];
        $stream = StreamDecoder::getDecodedStream($ctx, $cmapObj);
        if ($stream === null) return $ctx->fontMapsCache[$fontObjNum] = [];
        return $ctx->fontMapsCache[$fontObjNum] = self::parseToUnicodeCMap($stream);
    }

    private static function parseToUnicodeCMap(string $cmap): array
    {
        $map = [];
        if (preg_match_all('/beginbfchar(.*?)endbfchar/s', $cmap, $secs)) {
            foreach ($secs[1] as $sec) {
                if (preg_match_all('/<([0-9A-Fa-f]+)>\s+<([0-9A-Fa-f]+)>/', $sec, $pairs, PREG_SET_ORDER)) {
                    foreach ($pairs as $p) {
                        $src = strtoupper($p[1]);
                        $dst = strtoupper($p[2]);
                        $txt = '';
                        for ($i=0;$i<strlen($dst);$i+=4) {
                            $cp = hexdec(substr($dst,$i,4));
                            $txt .= self::cpToUtf8($cp);
                        }
                        $map[$src]=$txt;
                    }
                }
            }
        }
        if (preg_match_all('/beginbfrange(.*?)endbfrange/s', $cmap, $secs2)) {
            foreach ($secs2[1] as $sec) {
                $lines = preg_split('/\r?\n/', trim($sec));
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line==='' || $line[0]!=='<') continue;
                    if (preg_match('/^<([0-9A-Fa-f]+)>\s+<([0-9A-Fa-f]+)>\s+<([0-9A-Fa-f]+)>$/',$line,$mm)){
                        $start=hexdec($mm[1]); $end=hexdec($mm[2]); $dst=hexdec($mm[3]);
                        for($cid=$start;$cid<=$end;$cid++,$dst++){
                            $key=strtoupper(str_pad(dechex($cid),4,'0',STR_PAD_LEFT));
                            $map[$key]=self::cpToUtf8($dst);
                        }
                        continue;
                    }
                    if (preg_match('/^<([0-9A-Fa-f]+)>\s+<([0-9A-Fa-f]+)>\s+\[([^\]]+)\]$/',$line,$mm)){
                        $start=hexdec($mm[1]); $end=hexdec($mm[2]);
                        if (preg_match_all('/<([0-9A-Fa-f]+)>/',$mm[3],$vals)){
                            $cid=$start;
                            foreach ($vals[1] as $hex){
                                if ($cid>$end) break;
                                $cp=hexdec($hex);
                                $key=strtoupper(str_pad(dechex($cid),4,'0',STR_PAD_LEFT));
                                $map[$key]=self::cpToUtf8($cp);
                                $cid++;
                            }
                        }
                    }
                }
            }
        }
        return $map;
    }

    private static function cpToUtf8(int $cp): string
    {
        if ($cp<=0x7F) return chr($cp);
        if ($cp<=0x7FF) return chr(0xC0|($cp>>6)).chr(0x80|($cp&0x3F));
        if ($cp<=0xFFFF)
            return chr(0xE0|($cp>>12)).chr(0x80|(($cp>>6)&0x3F)).chr(0x80|($cp&0x3F));
        return chr(0xF0|($cp>>18))
             . chr(0x80|(($cp>>12)&0x3F))
             . chr(0x80|(($cp>>6)&0x3F))
             . chr(0x80|($cp&0x3F));
    }
}