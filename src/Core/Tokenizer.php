<?php
namespace ParserPDF\Core;

class Tokenizer
{
    public static function tokenize(string $content): array
    {
        $pattern='/
            (\[(?:[^\\\\\]]|\\\\.)*?\])|
            (\((?:[^()\\\\]|\\\\.)*?\))|
            <([0-9A-Fa-f]+)>|
            \/[A-Za-z0-9_.#]+|
            BT|ET|Tf|Tj|TJ|Tm|Td|TD|T\*|
            m|l|h|re|S|s|n|f|F|
            -?\d+(?:\.\d+)?
        /x';
        preg_match_all($pattern,$content,$m);
        $raw=$m[0];
        $tokens=[];
        foreach ($raw as $r){
            if (preg_match('/^(BT|ET|Tf|Tj|TJ|Tm|Td|TD|T\*|m|l|h|re|S|s|n|f|F)$/',$r)){
                $tokens[]=['type'=>'op','value'=>$r]; continue;
            }
            if ($r[0]==='/'){ $tokens[]=['type'=>'name','value'=>$r]; continue; }
            if ($r[0]==='('){ $tokens[]=['type'=>'string','value'=>substr($r,1,-1)]; continue; }
            if ($r[0]==='['){ $tokens[]=['type'=>'array','items'=>self::parseArray($r)]; continue; }
            if (preg_match('/^-?\d/',$r)){ $tokens[]=['type'=>'number','value'=>$r]; continue; }
            if ($r[0]==='<' && substr($r,-1)==='>' && preg_match('/<([0-9A-Fa-f]+)>/',$r,$hm))
                $tokens[]=['type'=>'hex','value'=>$hm[1]];
        }
        return $tokens;
    }

    private static function parseArray(string $raw): array
    {
        $inner=substr($raw,1,-1);
        $pattern='/\((?:[^()\\\\]|\\\\.)*?\)|<([0-9A-Fa-f]+)>|-?\d+(?:\.\d+)?/';
        $items=[];
        if (preg_match_all($pattern,$inner,$m)){
            foreach ($m[0] as $tok){
                if ($tok[0]==='(') $items[]=['type'=>'string','value'=>substr($tok,1,-1)];
                elseif ($tok[0]==='<' && preg_match('/<([0-9A-Fa-f]+)>/',$tok,$hm)) $items[]=['type'=>'hex','value'=>$hm[1]];
                elseif (preg_match('/^-?\d/',$tok)) $items[]=['type'=>'number','value'=>$tok];
            }
        }
        return $items;
    }
}