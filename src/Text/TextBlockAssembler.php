<?php
namespace ParserPDF\Text;

use ParserPDF\Core\Context;

class TextBlockAssembler
{
    private const SINGLE_PREPOSITIONS       = ['з','в','у','і'];
    private const SHORT_FROM_PREP_WHITELIST = ['за','зі','із','на','та'];
    private const PLACEHOLDER_CHARS         = ['X','Х'];

    private const UPPER_JOIN_FACTOR          = 1.4;
    private const LETTER_JOIN_FACTOR         = 0.65;
    private const LETTER_CHUNK_JOIN_FACTOR   = 1.10;
    private const INITIAL_UPPER_LOWER_FACTOR = 1.20;
    private const SHORT_PREP_GLUE_FACTOR     = 0.90;
    private const PREPOSITION_FORCE_SPACE    = true;
    private const UPPER_POST_COMBINE_FACTOR  = 1.55;

    public static function build(Context $ctx, array $fragmentsOutsideTables): array
    {
        if (!$fragmentsOutsideTables) return [];

        $lineTolFactor      = (float)($ctx->options['text_line_y_tolerance_factor'] ?? 0.4);
        $charGapFactor      = (float)($ctx->options['text_char_gap_factor'] ?? 0.18);
        $wordGapFactor      = (float)($ctx->options['text_word_gap_factor'] ?? 0.55);
        $paraGapFactor      = (float)($ctx->options['text_paragraph_gap_factor'] ?? 1.6);
        $indentTolerance    = (float)($ctx->options['text_indent_tolerance'] ?? 12.0);
        $trimLineEdges      = (bool)($ctx->options['text_trim_line_edges'] ?? true);
        $preserveMultiSpace = (bool)($ctx->options['text_preserve_multiple_spaces'] ?? false);
        $forceSpaceIfPrevTrailing = (bool)($ctx->options['text_force_space_if_prev_trailing'] ?? true);
        $removeTrailingJoin = (bool)($ctx->options['text_join_remove_trailing_space'] ?? true);

        $pages=[];
        foreach ($fragmentsOutsideTables as $f){
            if ($f['text']==='') continue;
            $pages[$f['page']][]=$f;
        }

        $components=[];
        foreach ($pages as $page=>$frags){
            usort($frags,function($a,$b){
                if ($a['page']!==$b['page']) return $a['page']<=>$b['page'];
                if (abs($b['y'] - $a['y']) > 0.00001) return $b['y'] <=> $a['y'];
                if ($a['x'] !== $b['x']) return $a['x'] <=> $b['x'];
                return ($a['seq']??0) <=> ($b['seq']??0);
            });

            $lines=[];
            foreach ($frags as $f){
                $fSize=$f['size'] ?: 12;
                $placed=false;
                foreach ($lines as &$ln){
                    $tol=$lineTolFactor * max($ln['avg_size'],$fSize);
                    if (abs($ln['y']-$f['y']) <= $tol){
                        $ln['items'][]=$f;
                        $ln['y']=($ln['y']*$ln['count']+$f['y'])/($ln['count']+1);
                        $ln['avg_size']=($ln['avg_size']*$ln['count']+$fSize)/($ln['count']+1);
                        $ln['count']++;
                        $placed=true; break;
                    }
                }
                unset($ln);
                if (!$placed){
                    $lines[]=[
                        'y'=>$f['y'],
                        'avg_size'=>$fSize,
                        'count'=>1,
                        'items'=>[$f]
                    ];
                }
            }

            $assembledLines=[];
            foreach ($lines as $ln){
                $items=$ln['items'];
                usort($items,function($a,$b){
                    if (abs($a['x'] - $b['x']) > 0.8) return $a['x'] <=> $b['x'];
                    return ($a['seq']??0) <=> ($b['seq']??0);
                });

                $tokens=self::tokenize($items);
                if (!$tokens) continue;
                $tokens=self::reorderEmbeddedPreposition($tokens);
                $tokens=self::mergeTokens($tokens,$charGapFactor,$wordGapFactor,$forceSpaceIfPrevTrailing,$removeTrailingJoin);
                if (!$tokens) continue;
                $tokens=self::combineUppercaseTokens($tokens);

                $lineText=self::assembleLineText($tokens,$trimLineEdges,$preserveMultiSpace);
                if ($lineText==='') continue;

                $xs=array_map(fn($t)=>$t['x1'],$tokens);
                $xe=array_map(fn($t)=>$t['x2'],$tokens);
                if (!$xs||!$xe) continue;
                $x1=min($xs); $x2=max($xe);
                $top=$ln['y']+$ln['avg_size']*0.3;
                $bottom=$ln['y']-$ln['avg_size']*0.8;

                $assembledLines[]=[
                    'y'=>$ln['y'],
                    'avg_size'=>$ln['avg_size'],
                    'text'=>$lineText,
                    'fragments'=>array_map(fn($i)=>[
                        'text'=>$i['text'],
                        'x'=>$i['x'],
                        'y'=>$i['y'],
                        'width'=>$i['width'],
                        'size'=>$i['size']
                    ], $items),
                    'x1'=>$x1,'x2'=>$x2,'y1'=>$bottom,'y2'=>$top
                ];
            }

            if (!$assembledLines) continue;
            usort($assembledLines,fn($a,$b)=> $b['y'] <=> $a['y']);
            $paragraphs=self::linesToParagraphs($assembledLines,$paraGapFactor,$indentTolerance);
            foreach ($paragraphs as $p){
                $text=implode("\n",$p['lines_text']);
                if (trim($text)==='') continue;
                $components[]=[
                    'type'=>'text',
                    'data'=>$text,
                    'params'=>[
                        'page'=>$page,
                        'bbox'=>$p['bbox'],
                        'fragments'=>$p['fragments']
                    ]
                ];
            }
        }
        return $components;
    }

    private static function tokenize(array $items): array
    {
        $tokens=[];
        foreach ($items as $it){
            $raw=$it['text'];
            if ($raw==='') continue;

            $leadingSpace = preg_match('/^\s/u',$raw)===1;
            $trailingSpace= preg_match('/\s$/u',$raw)===1;
            $isPureSpace  = preg_match('/^\s+$/u',$raw)===1;

            if ($isPureSpace){
                $tokens[]=[
                    'text'=>'','raw_text'=>$raw,'len'=>0,
                    'letters'=>false,'upper'=>false,'placeholder'=>false,'preposition'=>false,
                    'is_space'=>true,'leading_space'=>$leadingSpace,'trailing_space'=>$trailingSpace,
                    'x'=>$it['x'],'y'=>$it['y'],'width'=>$it['width'],'size'=>$it['size'],
                    'x1'=>$it['x'],'x2'=>$it['x']+$it['width'],
                    'force_space_after'=>true
                ];
                continue;
            }

            $clean=trim($raw);
            $letters=$clean!=='' && preg_match('/^\p{L}+$/u',$clean)===1;
            $upper=$letters && preg_match('/^\p{Lu}+$/u',$clean)===1;
            $placeholder=self::isPlaceholder($clean);
            $preposition=self::isSinglePreposition($clean);
            $len = $clean===''?0:mb_strlen($clean,'UTF-8');

            $tokens[]=[
                'text'=>$clean,'raw_text'=>$raw,'len'=>$len,
                'letters'=>$letters,'upper'=>$upper,'placeholder'=>$placeholder,'preposition'=>$preposition,
                'is_space'=>false,'leading_space'=>$leadingSpace,'trailing_space'=>$trailingSpace,
                'x'=>$it['x'],'y'=>$it['y'],'width'=>$it['width'],'size'=>$it['size'],
                'x1'=>$it['x'],'x2'=>$it['x']+$it['width'],
                'force_space_after'=>$trailingSpace
            ];
        }
        for ($i=0;$i<count($tokens)-1;$i++){
            $tokens[$i]['gap_after']=$tokens[$i+1]['x1'] - $tokens[$i]['x2'];
        }
        return $tokens;
    }

    private static function reorderEmbeddedPreposition(array $tokens): array
    {
        for ($i=0;$i<count($tokens)-1;$i++){
            $A=$tokens[$i]; $B=$tokens[$i+1];
            if ($A['placeholder'] && $B['preposition']){
                if ($B['x1']>$A['x1'] && $B['x1']<$A['x2']){
                    $tokens[$i]=$B; $tokens[$i+1]=$A;
                }
            }
        }
        return $tokens;
    }

    private static function mergeTokens(array $tokens,float $charGapFactor,float $wordGapFactor,bool $forceSpaceIfPrevTrailing,bool $removeTrailingJoin): array
    {
        $out=[];
        for ($i=0;$i<count($tokens);$i++){
            $t=$tokens[$i];
            if ($t['is_space']) {
                if ($out) $out[count($out)-1]['force_space_after']=true;
                continue;
            }
            if (!$out){ $out[]=$t; continue; }

            $pIdx=count($out)-1; $prev=$out[$pIdx];
            $prevForced = !empty($prev['force_space_after']) && $forceSpaceIfPrevTrailing;
            $realGap = $t['x1'] - $prev['x2']; if ($realGap<0) $realGap=0;
            $avgSize = ($prev['size']+$t['size'])/2 ?: 12;
            $charGap = $avgSize * $charGapFactor;
            $wordGap = $avgSize * $wordGapFactor;
            $canJoin=false; $forceSpace=false; $createdShort=false;

            if ($prevForced) $forceSpace=true;

            if (!$forceSpace && !$canJoin &&
                $prev['preposition'] && !$prev['placeholder'] &&
                $t['letters'] && !$t['placeholder'] && !$t['preposition'] &&
                !$prev['force_space_after']
            ){
                $cand=mb_strtolower($prev['text'].$t['text'],'UTF-8');
                if (in_array($cand,self::SHORT_FROM_PREP_WHITELIST,true) &&
                    $realGap <= self::SHORT_PREP_GLUE_FACTOR * $avgSize){
                    $canJoin=true; $createdShort=true;
                }
            }

            if (!$forceSpace && !$canJoin &&
                $prev['upper'] && $prev['len']==1 &&
                $t['letters'] && !$t['upper'] &&
                !$prev['force_space_after'] &&
                $realGap <= self::INITIAL_UPPER_LOWER_FACTOR * $avgSize){
                $canJoin=true;
            }

            if (!$forceSpace && !$canJoin &&
                $prev['upper'] && $t['upper'] &&
                !$prev['placeholder'] && !$t['placeholder'] &&
                !$prev['force_space_after'] &&
                $prev['len']<=4 && $t['len']<=4 &&
                $realGap <= self::UPPER_JOIN_FACTOR * $avgSize){
                $canJoin=true;
            }

            if (!$forceSpace && !$canJoin &&
                $prev['letters'] && $t['letters'] &&
                !$prev['placeholder'] && !$t['placeholder'] &&
                !$prev['force_space_after']
            ){
                if ($realGap <= self::LETTER_JOIN_FACTOR * $avgSize) {
                    $canJoin=true;
                } elseif ($prev['len']<=3 && $t['len']<=3 &&
                          $realGap <= self::LETTER_CHUNK_JOIN_FACTOR * $avgSize){
                    $canJoin=true;
                }
            }

            if ($prev['preposition'] && !$createdShort && !$canJoin){
                if ($t['placeholder']) $forceSpace=true;
                else if (self::PREPOSITION_FORCE_SPACE) $forceSpace=true;
            }

            if ($prev['placeholder'] && $t['preposition']) $forceSpace=true;

            if (!$canJoin && !$forceSpace){
                if ($realGap <= $charGap) $canJoin=true;
                else if ($realGap <= $wordGap) $forceSpace=true;
                else $forceSpace=true;
            }

            if ($canJoin){
                if ($removeTrailingJoin && preg_match('/\s$/u',$prev['raw_text']))
                    $prev['raw_text']=rtrim($prev['raw_text']);
                $prev['text'] .= $t['text'];
                $prev['raw_text'] .= $t['raw_text'];
                $prev['x2'] = max($prev['x2'],$t['x2']);
                $prev['width'] = $prev['x2'] - $prev['x1'];
                $prev['len'] = mb_strlen($prev['text'],'UTF-8');
                $prev['letters'] = $prev['letters'] && $t['letters'];
                $prev['upper'] = $prev['upper'] && $t['upper'];
                $prev['placeholder'] = false;
                if ($t['trailing_space']) $prev['force_space_after']=true;
                $out[$pIdx]=$prev;
            } else {
                if ($forceSpace) $out[$pIdx]['force_space_after']=true;
                $out[]=$t;
            }
        }
        return $out;
    }

    private static function combineUppercaseTokens(array $tokens): array
    {
        if (!$tokens) return $tokens;
        $res=[]; $cur=null;
        for ($i=0;$i<count($tokens);$i++){
            $t=$tokens[$i];
            if ($cur===null){ $cur=$t; continue; }
            $gap = $t['x1'] - $cur['x2']; if ($gap<0) $gap=0;
            $avg=($cur['size']+$t['size'])/2 ?: 12;
            $sameCase = $cur['upper'] && $t['upper'];
            $bothNotPlaceholder = !$cur['placeholder'] && !$t['placeholder'];
            if ($cur['placeholder'] && mb_strlen($cur['text'],'UTF-8')<3) $bothNotPlaceholder=true;
            if ($t['placeholder'] && mb_strlen($t['text'],'UTF-8')<3) $bothNotPlaceholder=$bothNotPlaceholder && true;

            $can = $sameCase && $bothNotPlaceholder && !$cur['force_space_after'] &&
                   $gap <= self::UPPER_POST_COMBINE_FACTOR * $avg;

            if ($can){
                $cur['text'] .= $t['text'];
                $cur['raw_text'] .= $t['raw_text'];
                $cur['x2']=max($cur['x2'],$t['x2']);
                $cur['width']=$cur['x2']-$cur['x1'];
                $cur['len']=mb_strlen($cur['text'],'UTF-8');
                if ($t['force_space_after']) $cur['force_space_after']=true;
            } else {
                $res[]=$cur;
                $cur=$t;
            }
        }
        if ($cur) $res[]=$cur;
        return $res;
    }

    private static function assembleLineText(array $tokens,bool $trimLineEdges,bool $preserveMultiSpace): string
    {
        if (!$tokens) return '';
        $parts=[];
        foreach ($tokens as $i=>$t){
            if ($t['len']===0) continue;
            if ($i===0){ $parts[]=$t['text']; continue; }
            $prev=$tokens[$i-1];
            $needSpace = !empty($prev['force_space_after']);

            if (!$needSpace && $prev['letters'] && $t['letters'])
                $needSpace=true;

            $parts[] = ($needSpace?' ':'').$t['text'];
        }
        $line=implode('',$parts);
        if (!$preserveMultiSpace) $line=preg_replace('/ {2,}/',' ',$line);
        if ($trimLineEdges) $line=trim($line);
        return $line;
    }

    private static function linesToParagraphs(array $lines,float $paraGapFactor,float $indentTolerance): array
    {
        if (!$lines) return [];
        $sizes=array_map(fn($l)=>$l['avg_size'],$lines);
        sort($sizes);
        $median=$sizes[(int)floor(count($sizes)/2)] ?: 12;
        $lineH=$median*1.2;
        $paraGap=$lineH*$paraGapFactor;

        $out=[]; $cur=null;
        foreach ($lines as $ln){
            if ($cur===null){ $cur=self::newParagraph($ln); continue; }
            $last=end($cur['lines']);
            $vGap=$last['y'] - $ln['y'];
            $xDiff=abs($last['x1'] - $ln['x1']);
            $same = ($vGap <= $paraGap) && ($xDiff <= $indentTolerance);
            if ($same){
                $cur['lines'][]=$ln;
                $cur['lines_text'][]=$ln['text'];
                $cur['fragments']=array_merge($cur['fragments'],$ln['fragments']);
                $cur['bbox']['x1']=min($cur['bbox']['x1'],$ln['x1']);
                $cur['bbox']['x2']=max($cur['bbox']['x2'],$ln['x2']);
                $cur['bbox']['y1']=min($cur['bbox']['y1'],$ln['y1']);
                $cur['bbox']['y2']=max($cur['bbox']['y2'],$ln['y2']);
            } else {
                $out[]=$cur;
                $cur=self::newParagraph($ln);
            }
        }
        if ($cur) $out[]=$cur;
        return $out;
    }

    private static function newParagraph(array $line): array
    {
        return [
            'lines'=>[$line],
            'lines_text'=>[$line['text']],
            'fragments'=>$line['fragments'],
            'bbox'=>[
                'x1'=>$line['x1'],
                'x2'=>$line['x2'],
                'y1'=>$line['y1'],
                'y2'=>$line['y2']
            ]
        ];
    }

    private static function isSinglePreposition(string $t): bool
    {
        return mb_strlen($t,'UTF-8')===1 &&
            in_array(mb_strtolower($t,'UTF-8'), self::SINGLE_PREPOSITIONS,true);
    }

    private static function isPlaceholder(string $t): bool
    {
        if ($t==='') return false;
        $len = mb_strlen($t,'UTF-8');
        $chars = preg_quote(implode('',self::PLACEHOLDER_CHARS),'/');

        if ($len>=2 && preg_match('/^['.$chars.']+$/u',$t)===1) return true;
        if ($len>=2 && preg_match('/^\d+$/u',$t)===1) return true;
        if ($len>=3 && preg_match('/^\p{Lu}+$/u',$t)===1) return true;
        return false;
    }
}