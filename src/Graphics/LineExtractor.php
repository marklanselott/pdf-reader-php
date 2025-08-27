<?php
namespace ParserPDF\Graphics;

use ParserPDF\Core\Context;
use ParserPDF\Core\StreamDecoder;
use ParserPDF\Core\Tokenizer;
use ParserPDF\Text\TextExtractor;
use ParserPDF\Util\Log;

class LineExtractor
{
    public static function extract(Context $ctx): void
    {
        foreach ($ctx->pages as $i=>$pageObj) {
            $pageNo = $i+1;
            $body   = $ctx->objects[$pageObj] ?? '';
            $contentRefs = self::findContentStreams($body);
            foreach ($contentRefs as $ref){
                $decoded = StreamDecoder::getDecodedStream($ctx,$ref);
                if ($decoded===null) continue;
                self::parseGraphics($ctx,$decoded,$pageNo);
            }
        }
        Log::d($ctx,"Всего граф. линий: ".count($ctx->lines));
    }

    private static function findContentStreams(string $pageBody): array
    {
        $nums=[];
        if (preg_match('/\/Contents\s+(\d+)\s+\d+\s+R/',$pageBody,$m)) {
            $nums[]=(int)$m[1];
            return $nums;
        }
        if (preg_match('/\/Contents\s+\[(.*?)\]/s',$pageBody,$m)) {
            if (preg_match_all('/(\d+)\s+\d+\s+R/',$m[1],$all)){
                foreach ($all[1] as $n) $nums[]=(int)$n;
            }
        }
        return $nums;
    }

    private static function parseGraphics(Context $ctx,string $content,int $page): void
    {
        $tokens=Tokenizer::tokenize($content);
        $path=[]; $stack=[];
        $flush=function() use (&$path,&$ctx,$page){
            if (count($path)<2){ $path=[]; return; }
            for($i=0;$i<count($path)-1;$i++){
                [$x1,$y1]=$path[$i]; [$x2,$y2]=$path[$i+1];
                $ctx->lines[] = self::lineRec($x1,$y1,$x2,$y2,$page);
            }
            $path=[];
        };

        foreach ($tokens as $t){
            if ($t['type']==='number'){ $stack[]=$t; continue; }
            if ($t['type']!=='op'){ $stack=[]; continue; }
            $op=$t['value'];
            switch($op){
                case 'm':
                    if (count($stack)>=2){
                        $y=array_pop($stack); $x=array_pop($stack);
                        $path=[[(float)$x['value'],(float)$y['value']]];
                    }
                    $stack=[]; break;
                case 'l':
                    if (count($stack)>=2){
                        $y=array_pop($stack); $x=array_pop($stack);
                        $path[]=[(float)$x['value'],(float)$y['value']];
                    }
                    $stack=[]; break;
                case 'h':
                    if (count($path)>1){
                        $first=$path[0]; $last=end($path);
                        if ($first!==$last) $path[]=$first;
                    }
                    $stack=[]; break;
                case 're':
                    if (count($stack)>=4){
                        $h=array_pop($stack); $w=array_pop($stack);
                        $y=array_pop($stack); $x=array_pop($stack);
                        $X=(float)$x['value']; $Y=(float)$y['value'];
                        $W=(float)$w['value']; $H=(float)$h['value'];
                        $ctx->lines[] = self::lineRec($X,$Y,$X+$W,$Y,$page);
                        $ctx->lines[] = self::lineRec($X+$W,$Y,$X+$W,$Y+$H,$page);
                        $ctx->lines[] = self::lineRec($X+$W,$Y+$H,$X,$Y+$H,$page);
                        $ctx->lines[] = self::lineRec($X,$Y+$H,$X,$Y,$page);
                    }
                    $stack=[]; break;
                case 'S': case 's':
                    $flush(); $stack=[]; break;
                case 'n': case 'f': case 'F':
                    $path=[]; $stack=[]; break;
                default:
                    $stack=[];
            }
        }
    }

    private static function lineRec(float $x1,float $y1,float $x2,float $y2,int $page): array
    {
        $orient='o';
        if (abs($y1-$y2)<0.5) $orient='h';
        elseif (abs($x1-$x2)<0.5) $orient='v';
        return [
            'page'=>$page,
            'x1'=>$x1,'y1'=>$y1,'x2'=>$x2,'y2'=>$y2,
            'orient'=>$orient,
            'length'=>sqrt(($x2-$x1)**2 + ($y2-$y1)**2)
        ];
    }
}