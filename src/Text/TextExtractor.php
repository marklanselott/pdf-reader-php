<?php
namespace ParserPDF\Text;

use ParserPDF\Core\Context;
use ParserPDF\Core\FontMapBuilder;
use ParserPDF\Core\StreamDecoder;
use ParserPDF\Core\Tokenizer;
use ParserPDF\Util\Log;

class TextExtractor
{
    private static int $seqCounter = 0;

    public static function extract(Context $ctx): void
    {
        foreach ($ctx->pages as $index => $pageObjNum) {
            $pageNo = $index + 1;
            $body   = $ctx->objects[$pageObjNum] ?? '';
            $contentRefs = self::findContentStreams($body);
            $fontMaps    = FontMapBuilder::buildPageFontMaps($ctx, $body);

            foreach ($contentRefs as $ref) {
                $decoded = StreamDecoder::getDecodedStream($ctx, $ref);
                if ($decoded === null) continue;
                self::parseContentText($ctx, $decoded, $fontMaps, $pageNo);
            }
        }
        Log::d($ctx, "Всего текстовых фрагментов: ".count($ctx->fragments));
    }

    private static function findContentStreams(string $pageBody): array
    {
        $nums=[];
        if (preg_match('/\/Contents\s+(\d+)\s+\d+\s+R/', $pageBody, $m)) {
            $nums[]=(int)$m[1];
            return $nums;
        }
        if (preg_match('/\/Contents\s+\[(.*?)\]/s',$pageBody,$m)) {
            if (preg_match_all('/(\d+)\s+\d+\s+R/', $m[1], $all)){
                foreach ($all[1] as $n) $nums[]=(int)$n;
            }
        }
        return $nums;
    }

    private static function parseContentText(Context $ctx,string $content,array $fontMaps,int $page): void
    {
        $tokens=Tokenizer::tokenize($content);
        $font=null; $size=12; $map=[];
        $tx=0;$ty=0;$lineStartX=0;$lineHeight=12;
        $stack=[];

        foreach ($tokens as $t){
            if (in_array($t['type'],['number','name','string','hex','array'],true)){
                $stack[]=$t; continue;
            }
            if ($t['type']!=='op'){ $stack=[]; continue; }
            $op=$t['value'];
            switch($op){
                case 'BT': case 'ET': $stack=[]; break;
                case 'Tf':
                    $sz=array_pop($stack);
                    $fn=array_pop($stack);
                    if ($sz && $fn && $fn['type']==='name' && $sz['type']==='number'){
                        $font=ltrim($fn['value'],'/');
                        $size=(float)$sz['value'] ?: $size;
                        $lineHeight=$size*1.2;
                        $map=$fontMaps[$font] ?? [];
                    }
                    $stack=[]; break;
                case 'Tm':
                    if (count($stack)>=6){
                        $fTok=array_pop($stack);
                        $eTok=array_pop($stack);
                        array_pop($stack);array_pop($stack);array_pop($stack);array_pop($stack);
                        $tx=(float)$eTok['value']; $ty=(float)$fTok['value']; $lineStartX=$tx;
                    }
                    $stack=[]; break;
                case 'Td':
                case 'TD':
                    if (count($stack)>=2){
                        $dy=array_pop($stack); $dx=array_pop($stack);
                        $tx+=(float)$dx['value']; $ty+=(float)$dy['value']; $lineStartX=$tx;
                    }
                    $stack=[]; break;
                case 'T*':
                    $ty-=$lineHeight; $tx=$lineStartX; $stack=[]; break;
                case 'Tj':
                    if ($stack){
                        $arg=array_pop($stack);
                        $text=self::decodeOperand($arg,$map);
                        if ($text!==''){
                            self::addFrag($ctx,$text,$tx,$ty,$font,$size,$page);
                            $tx+=self::approxWidth($text,$size);
                        }
                    }
                    $stack=[]; break;
                case 'TJ':
                    if ($stack){
                        $arr=array_pop($stack);
                        if ($arr['type']==='array'){
                            foreach ($arr['items'] as $item){
                                if ($item['type']==='string' || $item['type']==='hex'){
                                    $text=self::decodeOperand($item,$map);
                                    if ($text!==''){
                                        self::addFrag($ctx,$text,$tx,$ty,$font,$size,$page);
                                        $tx+=self::approxWidth($text,$size);
                                    }
                                } elseif ($item['type']==='number'){
                                    $adj=(float)$item['value'];
                                    $tx+= -$adj/1000.0*$size;
                                }
                            }
                        }
                    }
                    $stack=[]; break;
                default:
                    $stack=[];
            }
        }
    }

    private static function decodeOperand(array $tok,array $map): string
    {
        if ($tok['type']==='string')
            return preg_replace('/\\\\([()\\\\])/', '$1',$tok['value']);
        if ($tok['type']==='hex'){
            $hex=strtoupper($tok['value']); $out='';
            for ($i=0;$i<strlen($hex);$i+=4){
                $cid=substr($hex,$i,4);
                $out .= $map[$cid] ?? '?';
            }
            return $out;
        }
        return '';
    }

    private static function approxWidth(string $text,float $size): float
    {
        $chars = max(1, mb_strlen($text,'UTF-8'));
        return $chars * ($size*0.5);
    }

    private static function addFrag(Context $ctx,string $text,float $x,float $y,?string $font,float $size,int $page): void
    {
        $ctx->fragments[]=[
            'seq'=> self::$seqCounter++,
            'page'=>$page,'text'=>$text,
            'x'=>$x,'y'=>$y,'font'=>$font,'size'=>$size,
            'width'=>self::approxWidth($text,$size),
            'height'=>$size*1.2
        ];
    }
}