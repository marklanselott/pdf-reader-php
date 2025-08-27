<?php
namespace ParserPDF\Tables;

use ParserPDF\Core\Context;
use ParserPDF\Util\Log;
use ParserPDF\Util\Value;

class LineTableDetector
{
    public static function detect(Context $ctx): array
    {
        Log::d($ctx,"Поиск таблиц (lines)...");
        $byPageLines=[]; $byPageFrags=[];
        foreach ($ctx->lines as $ln) $byPageLines[$ln['page']][]=$ln;
        foreach ($ctx->fragments as $f) $byPageFrags[$f['page']][]=$f;

        $xTol=(float)($ctx->options['line_xTolerance'] ?? 1.5);
        $yTol=(float)($ctx->options['line_yTolerance'] ?? 1.5);
        $minVL=(float)($ctx->options['line_minVerticalLength'] ?? 10);
        $minHL=(float)($ctx->options['line_minHorizontalLength'] ?? 10);
        $minCols=(int)($ctx->options['line_minCols'] ?? 2);
        $minRows=(int)($ctx->options['line_minRows'] ?? 2);
        $maxAreaRatio=(float)($ctx->options['line_max_page_area_ratio'] ?? 0.5);
        $minFilledRatio=(float)($ctx->options['line_min_filled_cells_ratio'] ?? 0.2);
        $stripEmpty = (bool)($ctx->options['line_strip_empty_rows'] ?? true);
        $cellCharGapFactor=(float)($ctx->options['table_cell_char_gap_factor'] ?? 0.22);

        $tables=[];
        foreach ($byPageLines as $page=>$lines){
            $frags=$byPageFrags[$page] ?? [];
            $tables=array_merge(
                $tables,
                self::detectOnPage(
                    $ctx,$page,$lines,$frags,
                    compact(
                        'xTol','yTol','minVL','minHL',
                        'minCols','minRows','maxAreaRatio',
                        'minFilledRatio','stripEmpty','cellCharGapFactor'
                    )
                )
            );
        }
        Log::d($ctx,"Таблиц по линиям: ".count($tables));
        return array_map(fn($t)=>$t+['origin'=>'line'],$tables);
    }

    private static function detectOnPage(Context $ctx,int $page,array $lines,array $frags,array $op): array
    {
        $pb=$ctx->pageBoxes[$page] ?? ['w'=>595.32,'h'=>841.92];
        $pw=$pb['w']; $ph=$pb['h'];

        $v=[]; $h=[];
        foreach ($lines as $ln){
            if ($ln['orient']==='v' && $ln['length'] >= $op['minVL']){
                if ($ln['length'] >= 0.9*$ph && ($ln['x1']<5 || $pw-max($ln['x1'],$ln['x2'])<5)) continue;
                $v[]=$ln;
            } elseif ($ln['orient']==='h' && $ln['length'] >= $op['minHL']){
                if ($ln['length'] >= 0.9*$pw && ($ln['y1']<5 || $ph-max($ln['y1'],$ln['y2'])<5)) continue;
                $h[]=$ln;
            }
        }
        if (count($v) < $op['minCols']+1 || count($h) < $op['minRows']+1) return [];

        $vx=self::clusterPositions($v,true,$op['xTol']);
        $hy=self::clusterPositions($h,false,$op['yTol']);
        if (count($vx) < $op['minCols']+1 || count($hy) < $op['minRows']+1) return [];

        sort($vx); rsort($hy);

        $colCount=count($vx)-1;
        $rowCount=count($hy)-1;
        if ($colCount < $op['minCols'] || $rowCount < $op['minRows']) return [];

        $matrix=[]; $bboxes=[]; $filled=0;
        for($r=0;$r<$rowCount;$r++){
            $yTop=$hy[$r]; $yBot=$hy[$r+1];
            $rowTexts=[]; $rowBB=[];
            for($c=0;$c<$colCount;$c++){
                $xL=$vx[$c]; $xR=$vx[$c+1];
                $txt=self::collectCellText($frags,$xL,$xR,$yBot,$yTop,$op['cellCharGapFactor']);
                if (trim($txt)!=='') $filled++;
                $rowTexts[]=$txt;
                $rowBB[]=['x1'=>$xL,'x2'=>$xR,'y1'=>$yBot,'y2'=>$yTop];
            }
            $matrix[]=$rowTexts;
            $bboxes[]=$rowBB;
        }

        if ($op['stripEmpty']) {
            $tmp=[];
            foreach ($matrix as $i=>$row){
                $empty=true;
                foreach ($row as $c) if (trim($c)!==''){ $empty=false; break; }
                $tmp[]=['row'=>$row,'bbox'=>$bboxes[$i],'empty'=>$empty];
            }
            while ($tmp && $tmp[0]['empty']) array_shift($tmp);
            while ($tmp && end($tmp)['empty']) array_pop($tmp);
            $comp=[];
            foreach ($tmp as $r) if (!$r['empty']) $comp[]=$r;
            if ($comp){
                $matrix=[]; $bboxes=[];
                foreach ($comp as $r){ $matrix[]=$r['row']; $bboxes[]=$r['bbox']; }
            }
        }
        if (count($matrix) < 2) return [];

        $bannerRows=[];
        foreach ($matrix as $ri=>$row){
            $nonEmptyIdx=[];
            foreach ($row as $ci=>$val)
                if (trim($val)!=='') $nonEmptyIdx[]=$ci;
            if (count($nonEmptyIdx)===1){
                $ci=$nonEmptyIdx[0];
                $txt=trim($row[$ci]);
                if ($txt!=='' && mb_strlen($txt,'UTF-8')<=6 && preg_match('/^\p{Lu}+$/u',$txt)){
                    $bannerRows[]=[
                        'original_index'=>$ri,
                        'text'=>$txt,
                        'bbox'=>$bboxes[$ri][$ci],
                        'col_index'=>$ci
                    ];
                }
            }
        }
        if ($bannerRows){
            $keepM=[]; $keepB=[];
            foreach ($matrix as $ri=>$row){
                $isBanner=false;
                foreach ($bannerRows as $br)
                    if ($br['original_index']===$ri){ $isBanner=true; break; }
                if (!$isBanner){
                    $keepM[]=$row; $keepB[]=$bboxes[$ri];
                }
            }
            $matrix=$keepM; $bboxes=$keepB;
        }
        if (count($matrix) < 2) return [];

        $headerIdx=null;
        for($i=0;$i<count($matrix);$i++){
            foreach ($matrix[$i] as $c)
                if (trim($c)!==''){ $headerIdx=$i; break; }
            if ($headerIdx!==null) break;
        }
        if ($headerIdx===null) return [];

        $segments = self::splitByRepeatingHeader($matrix,$bboxes,$headerIdx);

        $delta = 2.0;
        if ($bannerRows){
            $segmentTops=[];
            foreach ($segments as $si=>$seg){
                [$segMatrix,$segBBoxes] = $seg;
                $segTop = max(array_map(fn($row)=>$row[0]['y2'],$segBBoxes));
                $segmentTops[$si]=$segTop;
            }
            foreach ($bannerRows as $br){
                $bannerYTop = $br['bbox']['y2'];
                $attached=false;
                for ($si=0;$si<count($segments);$si++){
                    $segTop = $segmentTops[$si];
                    if ($segTop < ($bannerYTop - $delta)){
                        $segments[$si][4][]=[
                            'text'=>$br['text'],
                            'bbox'=>$br['bbox'],
                            'page'=>$page
                        ];
                        $attached=true;
                        break;
                    }
                }
                if (!$attached){
                    $best=null; $bestDiff=null;
                    for ($si=0;$si<count($segments);$si++){
                        $diff = $bannerYTop - $segmentTops[$si];
                        if ($diff>=0 && ($bestDiff===null || $diff < $bestDiff)){
                            $best=$si; $bestDiff=$diff;
                        }
                    }
                    if ($best===null) $best = count($segments)-1;
                    $segments[$best][4][]=[
                        'text'=>$br['text'],
                        'bbox'=>$br['bbox'],
                        'page'=>$page
                    ];
                }
            }
        }

        $vxMin=min($vx); $vxMax=max($vx);

        $outTables=[];
        foreach ($segments as $segIndex=>$segment){
            [$segMatrix,$segBBoxes,$segHeaderIdx,$globalRowIndices,$attachedBanners] = $segment;
            if (count($segMatrix) < 2) continue;

            $headersRaw = $segMatrix[$segHeaderIdx];
            $headers=[]; $used=[];
            foreach ($headersRaw as $ci=>$h){
                $name = trim($h)===''? 'col_'.($ci+1) : trim($h);
                $base=$name; $k=2;
                while(isset($used[$name])){ $name=$base.'_'.$k; $k++; }
                $used[$name]=true;
                $headers[$ci]=$name;
            }

            $dataRows = array_slice($segMatrix,$segHeaderIdx+1);
            $dataBBoxes = array_slice($segBBoxes,$segHeaderIdx+1);
            if (!$dataRows) continue;

            $rowsAssoc=[];
            foreach ($dataRows as $r){
                $assoc=[];
                foreach ($r as $ci=>$val){
                    $assoc[$headers[$ci]] = Value::cast($val);
                }
                $rowsAssoc[]=$assoc;
            }

            $segYTop = max(array_map(fn($row)=>$row[0]['y2'],$segBBoxes));
            $segYBot = min(array_map(fn($row)=>$row[0]['y1'],$segBBoxes));
            $segBBox=['x1'=>$vxMin,'x2'=>$vxMax,'y1'=>$segYBot,'y2'=>$segYTop];

            $colCenters=self::centers($vx);
            $rowCenters=[];
            foreach ($globalRowIndices as $gi){
                if (isset($hy[$gi])) $rowCenters[]=$hy[$gi];
            }

            $cells=[];
            $headerCells=[];
            $headerBBox = $segBBoxes[$segHeaderIdx] ?? [];
            foreach ($headersRaw as $ci=>$txt){
                $headerCells[]=[
                    'row_type'=>'header',
                    'col'=>$ci,
                    'header_name'=>$headers[$ci],
                    'text'=>$txt,
                    'bbox'=>$headerBBox[$ci] ?? null
                ];
            }
            $cells[]=$headerCells;
            foreach ($dataRows as $ri=>$row){
                $rowCells=[];
                $bbRow = $dataBBoxes[$ri] ?? [];
                foreach ($row as $ci=>$txt){
                    $rowCells[]=[
                        'row_type'=>'data',
                        'row_index'=>$ri,
                        'col'=>$ci,
                        'header_name'=>$headers[$ci],
                        'text'=>$txt,
                        'bbox'=>$bbRow[$ci] ?? null
                    ];
                }
                $cells[]=$rowCells;
            }

            $rawMatrix = array_merge([$headersRaw], $dataRows);

            $outTables[]=[
                'page'=>$page,
                'headers'=>$headers,
                'rows_assoc'=>$rowsAssoc,
                'raw_matrix'=>$rawMatrix,
                'col_centers'=>$colCenters,
                'row_baselines'=>$rowCenters,
                'bbox'=>$segBBox,
                'cells'=>$cells,
                'detached_texts'=>$attachedBanners
            ];
        }

        return $outTables;
    }

    private static function splitByRepeatingHeader(array $matrix,array $bboxes,int $headerIdx): array
    {
        $header = $matrix[$headerIdx];
        $starts = [$headerIdx];
        for ($i=$headerIdx+1; $i<count($matrix); $i++){
            if (self::rowsEqual($header,$matrix[$i])) $starts[]=$i;
        }
        if (count($starts)===1) {
            return [[ $matrix, $bboxes, $headerIdx, range(0,count($matrix)-1), [] ]];
        }
        $segments=[];
        for ($s=0; $s<count($starts); $s++){
            $start = $starts[$s];
            $end   = ($s+1 < count($starts)) ? $starts[$s+1]-1 : count($matrix)-1;
            $segMatrix = array_slice($matrix,$start,$end-$start+1);
            $segBBoxes = array_slice($bboxes,$start,$end-$start+1);
            $globalIndices = range($start,$end);
            $segments[]=[ $segMatrix, $segBBoxes, 0, $globalIndices, [] ];
        }
        return $segments;
    }

    private static function rowsEqual(array $a,array $b): bool
    {
        if (count($a)!==count($b)) return false;
        for ($i=0;$i<count($a);$i++){
            if (trim((string)$a[$i]) !== trim((string)$b[$i])) return false;
        }
        return true;
    }

    private static function clusterPositions(array $lines,bool $vertical,float $tol): array
    {
        $vals=[];
        foreach ($lines as $ln){
            $vals[] = $vertical ? ($ln['x1']+$ln['x2'])/2 : ($ln['y1']+$ln['y2'])/2;
        }
        sort($vals);
        $clusters=[];
        foreach ($vals as $v){
            $placed=false;
            foreach ($clusters as &$c){
                if (abs($c['avg'] - $v) <= $tol){
                    $c['sum']+=$v; $c['count']++; $c['avg']=$c['sum']/$c['count'];
                    $placed=true; break;
                }
            }
            unset($c);
            if (!$placed) $clusters[]=['sum'=>$v,'count'=>1,'avg'=>$v];
        }
        return array_map(fn($c)=>$c['avg'],$clusters);
    }

    private static function collectCellText(array $frags,float $x1,float $x2,float $y1,float $y2,float $charGapFactor): string
    {
        $inside=[];
        foreach ($frags as $f){
            if ($f['x'] >= $x1-0.5 && $f['x'] <= $x2+0.5 &&
                $f['y'] >= $y1-0.5 && $f['y'] <= $y2+0.5){
                $inside[]=$f;
            }
        }
        if (!$inside) return '';
        usort($inside,fn($a,$b)=> $a['x'] <=> $b['x']);

        $text=''; $prev=null;
        foreach ($inside as $it){
            $raw=str_replace(["\r","\n"],' ',$it['text']);
            $raw=preg_replace('/\s+/u',' ',$raw);
            if ($prev===null){
                $text.=$raw; $prev=$it; continue;
            }
            $gap = $it['x'] - ($prev['x'] + $prev['width']);
            $avgSize = ($prev['size'] + $it['size'])/2 ?: 12;
            $charGap = $avgSize * $charGapFactor;
            $noSpace = ($gap <= $charGap) || ($gap < 0.1);
            if ($noSpace && preg_match('/\s$/u',$text)) $text = rtrim($text);
            $text .= ($noSpace? '' : ' ') . ltrim($raw);
            $prev=$it;
        }
        $text = preg_replace_callback('/\b([A-Z])(?:\s+([A-Z]))+\b/u', fn($m)=>str_replace(' ','',$m[0]), $text);
        return trim($text);
    }

    private static function centers(array $edges): array
    {
        $out=[];
        for ($i=0;$i<count($edges)-1;$i++) $out[]=($edges[$i]+$edges[$i+1])/2;
        return $out;
    }
}