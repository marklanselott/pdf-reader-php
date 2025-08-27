<?php
namespace ParserPDF\Assemble;

use ParserPDF\Core\Context;
use ParserPDF\Text\TextBlockAssembler;
use ParserPDF\Util\Log;
use ParserPDF\Tables\TextTableNormalizer;
use ParserPDF\Tables\TableStructureNormalizer;

class ComponentBuilder
{
    public static function build(Context $ctx, array $tables): array
    {
        Log::d($ctx,"Формирование компонент...");

        $tables = TableStructureNormalizer::normalizeMany($tables);
        $tables = TextTableNormalizer::normalize($ctx, $tables);
        $tables = self::dedupeTextVsLine($tables);

        $mode = $ctx->options['table_data_mode'] ?? 'array';
        $modeKey = $ctx->options['table_data_mode_key'] ?? null;
        $modeValue = $ctx->options['table_data_mode_value'] ?? null;

        foreach ($tables as &$tbl) {
            $tbl['rows_assoc'] = self::remapRows($tbl['rows_assoc'], $mode, $modeKey, $modeValue);
        }
        unset($tbl);

        $tableAreas=[]; $components=[]; $detachedTextFrags=[];

        foreach ($tables as $tbl){
            $a=$tbl['bbox']; $a['page']=$tbl['page'];
            $tableAreas[]=$a;

            if (!empty($tbl['detached_texts'])){
                foreach ($tbl['detached_texts'] as $dt){
                    $detachedTextFrags[] = [
                        'text'=>$dt['text'],
                        'x'=>$dt['bbox']['x1'],
                        'y'=>$dt['bbox']['y2'],
                        'width'=>$dt['bbox']['x2']-$dt['bbox']['x1'],
                        'size'=>12,
                        'page'=>$dt['page']
                    ];
                }
            }

            $params=[
                'page'=>$tbl['page'],
                'headers'=>$tbl['headers'],
                'raw_matrix'=>$tbl['raw_matrix'],
                'column_centers'=>$tbl['col_centers'] ?? ($tbl['params']['column_centers'] ?? []),
                'row_baselines'=>$tbl['row_baselines'],
                'bbox'=>$tbl['bbox'],
                'origin'=>$tbl['origin'],
                'data_mode'=>$mode
            ];
            if (isset($tbl['cells'])) $params['cells']=$tbl['cells'];

            $components[]=[
                'type'=>'table',
                'data'=>$tbl['rows_assoc'],
                'params'=>$params
            ];
        }

        $outside = array_filter($ctx->fragments,function($f) use ($tableAreas){
            foreach ($tableAreas as $a){
                if ($f['page']===$a['page'] &&
                    $f['x'] >= $a['x1'] && $f['x'] <= $a['x2'] &&
                    $f['y'] >= $a['y1'] && $f['y'] <= $a['y2']) return false;
            }
            return true;
        });
        foreach ($detachedTextFrags as $f) $outside[]=$f;

        $textComponents = TextBlockAssembler::build($ctx, $outside);
        $textComponents = self::dedupeUppercaseLabelsAfterBuild($textComponents);

        $components = array_merge($components, $textComponents);

        usort($components,function($a,$b){
            $pa=$a['params']['page']??1; $pb=$b['params']['page']??1;
            if ($pa!==$pb) return $pa <=> $pb;
            $ya=$a['params']['bbox']['y2']??0;
            $yb=$b['params']['bbox']['y2']??0;
            return $yb <=> $ya;
        });

        return $components;
    }

    private static function remapRows($rows, string $mode, ?string $key, ?string $val) {
        if (!is_array($rows)) return $rows;
        if ($mode === 'array') return $rows;

        if ($mode === 'map_first_col') {
            $out=[];
            foreach ($rows as $r){
                if (!is_array($r)) continue;
                $keys=array_keys($r);
                if (!$keys) continue;
                $k=(string)$r[$keys[0]];
                $tmp=$r;
                unset($tmp[$keys[0]]);
                $out[$k]=$tmp;
            }
            return $out;
        }

        if ($mode === 'map_first_to_last') {
            $out=[];
            foreach ($rows as $r){
                if (!is_array($r)) continue;
                $keys=array_keys($r);
                if (count($keys)<2) continue;
                $k=(string)$r[$keys[0]];
                $out[$k]=$r[$keys[count($keys)-1]];
            }
            return $out;
        }

        if ($mode === 'map_key') {
            if (!$key) return $rows;
            $out=[];
            foreach ($rows as $r){
                if (!array_key_exists($key,$r)) continue;
                $k=(string)$r[$key];
                if ($val){
                    if (array_key_exists($val,$r)){
                        $out[$k]=$r[$val];
                    }
                } else {
                    $tmp=$r;
                    unset($tmp[$key]);
                    $out[$k]=$tmp;
                }
            }
            return $out;
        }

        return $rows;
    }

    private static function dedupeTextVsLine(array $tables): array
    {
        $lineTables=[];
        foreach ($tables as $idx=>$t){
            if (($t['origin']??'')==='line') $lineTables[$idx]=$t;
        }
        if (!$lineTables) return $tables;

        $dropIndices=[];
        foreach ($tables as $i=>$tbl){
            if (($tbl['origin']??'')!=='text') continue;
            foreach ($lineTables as $j=>$lt){
                if ($tbl['page']!==$lt['page']) continue;
                if (self::bboxInside($tbl['bbox'],$lt['bbox']) &&
                    self::headersEqual($tbl['headers'],$lt['headers']) &&
                    self::rowsSubset($tbl['rows_assoc'],$lt['rows_assoc'])
                ){
                    $dropIndices[$i]=true;
                    break;
                }
            }
        }
        if ($dropIndices){
            $tables = array_values(array_filter($tables, fn($t,$k)=>!isset($dropIndices[$k]), ARRAY_FILTER_USE_BOTH));
        }
        return $tables;
    }

    private static function dedupeUppercaseLabelsAfterBuild(array $texts): array
    {
        if (!$texts) return $texts;

        $byPage=[];
        foreach ($texts as $i=>$t){
            $page=$t['params']['page'] ?? 1;
            $byPage[$page][]=$i;
        }
        $drop=[];

        foreach ($byPage as $page=>$indices){
            $longers=[];
            foreach ($indices as $idx){
                $label=trim($texts[$idx]['data']);
                if (mb_strlen($label,'UTF-8')>=2 && mb_strlen($label,'UTF-8')<=12 && preg_match('/^\p{Lu}+$/u',$label)){
                    $longers[]=$idx;
                }
            }

            $groups=[];
            foreach ($longers as $idx){
                $label=trim($texts[$idx]['data']);
                $groups[$label][]=$idx;
            }
            foreach ($groups as $label=>$g){
                if (count($g)<2) continue;
                usort($g,function($a,$b) use ($texts){
                    $ya=$texts[$a]['params']['bbox']['y2']; $yb=$texts[$b]['params']['bbox']['y2'];
                    return $yb <=> $ya;
                });
                $keep=array_shift($g);
                $keepBox=$texts[$keep]['params']['bbox'];
                foreach ($g as $cand){
                    $candBox=$texts[$cand]['params']['bbox'];
                    if (self::hOverlapRatio($keepBox,$candBox)>=0.4 &&
                        abs($keepBox['y2']-$candBox['y2'])<120){
                        $drop[$cand]=true;
                    }
                }
            }

            foreach ($indices as $idx){
                if (isset($drop[$idx])) continue;
                $label=trim($texts[$idx]['data']);
                if (mb_strlen($label,'UTF-8')===1 && preg_match('/^\p{Lu}$/u',$label)){
                    $box=$texts[$idx]['params']['bbox'];
                    $char=$label;
                    $remove=false;
                    foreach ($longers as $li){
                        $full=trim($texts[$li]['data']);
                        if (mb_strpos($full,$char,0,'UTF-8')!==false){
                            $box2=$texts[$li]['params']['bbox'];
                            if (self::hOverlapRatio($box,$box2)>=0.2 &&
                                abs($box['y2']-$box2['y2'])<150){
                                $remove=true; break;
                            }
                        }
                    }
                    if ($remove) $drop[$idx]=true;
                }
            }
        }

        if ($drop){
            $texts = array_values(array_filter($texts, fn($c,$i)=>!isset($drop[$i]), ARRAY_FILTER_USE_BOTH));
        }
        return $texts;
    }

    private static function bboxInside(array $a,array $b): bool
    {
        return $a['x1'] >= $b['x1']-0.5 &&
               $a['x2'] <= $b['x2']+0.5 &&
               $a['y1'] >= $b['y1']-0.5 &&
               $a['y2'] <= $b['y2']+0.5;
    }
    private static function headersEqual(array $h1,array $h2): bool
    {
        if (count($h1)!==count($h2)) return false;
        for ($i=0;$i<count($h1);$i++){
            if ($h1[$i] !== $h2[$i]) return false;
        }
        return true;
    }
    private static function rowsSubset(array $small,array $big): bool
    {
        $bigHashes=[];
        foreach ($big as $row){
            $bigHashes[md5(json_encode($row,JSON_UNESCAPED_UNICODE))]=true;
        }
        foreach ($small as $row){
            if (!isset($bigHashes[md5(json_encode($row,JSON_UNESCAPED_UNICODE))])) return false;
        }
        return true;
    }
    private static function hOverlapRatio(array $a,array $b): float
    {
        $l=max($a['x1']??0,$b['x1']??0);
        $r=min($a['x2']??0,$b['x2']??0);
        if ($r <= $l) return 0.0;
        $wA=max(1,($a['x2']??0)-($a['x1']??0));
        $wB=max(1,($b['x2']??0)-($b['x1']??0));
        $ov=$r-$l;
        return $ov / min($wA,$wB);
    }
}