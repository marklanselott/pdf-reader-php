<?php
namespace ParserPDF\Tables;

use ParserPDF\Core\Context;
use ParserPDF\Util\Log;
use ParserPDF\Util\Value;

class TextTableDetector
{
    public static function detect(Context $ctx): array
    {
        Log::d($ctx,"Поиск таблиц (text)...");
        $byPage=[];
        foreach ($ctx->fragments as $f) $byPage[$f['page']][]=$f;

        $yTol=(float)($ctx->options['table_yTolerance'] ?? 6.0);
        $xTol=(float)($ctx->options['table_xTolerance'] ?? 6.0);
        $mergeTol=(float)($ctx->options['table_colMergeTolerance'] ?? 8.0);
        $maxMergeSpan=(float)($ctx->options['table_maxMergeSpan'] ?? 50.0);

        $tables=[];
        foreach ($byPage as $page=>$frags){
            $tables = array_merge($tables, self::detectOnPage($frags,$page,$yTol,$xTol,$mergeTol,$maxMergeSpan));
        }
        Log::d($ctx,"Текстовых таблиц: ".count($tables));
        return array_map(fn($t)=>$t+['origin'=>'text'],$tables);
    }

    private static function detectOnPage(array $frags,int $page,float $yTol,float $xTol,float $mergeTol,float $maxMergeSpan): array
    {
        $frags=array_values(array_filter($frags,fn($f)=>trim($f['text'])!==''));
        usort($frags,fn($a,$b)=> $b['y'] <=> $a['y']);
        $rows=[];
        foreach ($frags as $f){
            $placed=false;
            foreach ($rows as &$r){
                if (abs($r['y']-$f['y']) <= $yTol){
                    $r['items'][]=$f; $r['y']=($r['y']+$f['y'])/2; $placed=true; break;
                }
            }
            if(!$placed) $rows[]=['y'=>$f['y'],'items'=>[$f]];
        }
        foreach ($rows as &$r){
            usort($r['items'],fn($a,$b)=> $a['x'] <=> $b['x']);
            $r['items']=self::mergeRowItems($r['items']);
        }
        unset($r);

        $xs=[];
        foreach ($rows as $r) foreach ($r['items'] as $it) $xs[]=$it['x'];
        sort($xs);
        $clusters=[];
        foreach ($xs as $x){
            $pl=false;
            foreach ($clusters as &$c){
                if (abs($c['avg']-$x) <= $xTol){
                    $c['sum']+=$x; $c['count']++; $c['avg']=$c['sum']/$c['count']; $pl=true; break;
                }
            }
            if(!$pl) $clusters[]=['sum'=>$x,'count'=>1,'avg'=>$x];
        }
        if (count($clusters)>1){
            $merged=[]; $cur=$clusters[0];
            for($i=1;$i<count($clusters);$i++){
                $c=$clusters[$i];
                $dist=abs($c['avg']-$cur['avg']);
                if ($dist <= $mergeTol && $dist <= $maxMergeSpan){
                    $cur['sum']+=$c['sum']; $cur['count']+=$c['count']; $cur['avg']=$cur['sum']/$cur['count'];
                } else { $merged[]=$cur; $cur=$c; }
            }
            $merged[]=$cur; $clusters=$merged;
        }
        usort($clusters,fn($a,$b)=> $a['avg']<=>$b['avg']);
        foreach ($clusters as $i=>&$c) $c['index']=$i;

        $blocks=[]; $cur=[];
        foreach ($rows as $row){
            $used=self::countColsUsed($row['items'],$clusters,$xTol);
            if ($used>=2){ $cur[]=$row; }
            else {
                if (count($cur)>=2) $blocks[]=$cur;
                $cur=[];
            }
        }
        if (count($cur)>=2) $blocks[]=$cur;

        $tables=[];
        foreach ($blocks as $block){
            $t=self::buildTable($block,$clusters,$xTol,$page);
            if ($t) $tables[]=$t;
        }
        return $tables;
    }

    private static function mergeRowItems(array $items): array
    {
        if (!$items) return $items;
        $res=[]; $buf=null;
        foreach ($items as $it){
            if ($buf===null){ $buf=$it; continue; }
            $gap=$it['x'] - ($buf['x'] + $buf['width']);
            $mergeGap=max(1.0,$buf['size']*0.6);
            if ($gap <= $mergeGap){
                $buf['text'].=$it['text'];
                $buf['width'] += $it['width'] + $gap;
            } else { $res[]=$buf; $buf=$it; }
        }
        if ($buf) $res[]=$buf;
        return $res;
    }

    private static function countColsUsed(array $items,array $clusters,float $xTol): int
    {
        $used=[];
        foreach ($items as $it){
            $ci=self::nearestCluster($it['x'],$clusters,$xTol);
            if ($ci!==null) $used[$ci]=true;
        }
        return count($used);
    }

    private static function nearestCluster(float $x,array $clusters,float $xTol): ?int
    {
        $best=null; $bestDist=$xTol;
        foreach ($clusters as $c){
            $d=abs($c['avg']-$x);
            if ($d <= $xTol && $d < $bestDist){
                $bestDist=$d; $best=$c['index'];
            }
        }
        return $best;
    }

    private static function buildTable(array $block,array $clusters,float $xTol,int $page): ?array
    {
        $matrix=[]; $colUsage=array_fill(0,count($clusters),0);
        foreach ($block as $r){
            $line=array_fill(0,count($clusters),'');
            foreach ($r['items'] as $it){
                $ci=self::nearestCluster($it['x'],$clusters,$xTol);
                if ($ci===null) continue;
                $line[$ci]=$line[$ci]===''?$it['text']:$line[$ci].' '.$it['text'];
            }
            foreach ($line as $ci=>$v) if ($v!=='') $colUsage[$ci]++;
            $matrix[]=$line;
        }
        if(!$matrix || count($matrix[0])<2) return null;
        $keep=[];
        foreach ($colUsage as $ci=>$cnt) if($cnt>0) $keep[]=$ci;
        if (count($keep)<2) return null;

        $final=[];
        foreach ($matrix as $row){
            $r=[];
            foreach ($keep as $ci) $r[]=trim($row[$ci]);
            $final[]=$r;
        }
        $headersRaw=$final[0];
        $headers=[]; $used=[];
        foreach ($headersRaw as $i=>$h){
            $nm = $h!=='' ? $h : "col_".($i+1);
            $base=$nm;$s=2;
            while(isset($used[$nm])){ $nm=$base."_".$s; $s++; }
            $used[$nm]=true; $headers[$i]=$nm;
        }
        $rowsAssoc=[];
        for($i=1;$i<count($final);$i++){
            $assoc=[];
            foreach ($final[$i] as $ci=>$val){
                $assoc[$headers[$ci]]=Value::cast($val);
            }
            $rowsAssoc[]=$assoc;
        }
        $centers=[];
        foreach ($keep as $ci) $centers[]=$clusters[$ci]['avg'];
        $baselines=array_map(fn($r)=>$r['y'],$block);
        $minX=min($centers)-3;
        $maxX=max($centers)+3;
        $fs=0;$cnt=0;
        foreach ($block as $r) foreach ($r['items'] as $it){ $fs+=$it['size']; $cnt++; }
        $fs=$cnt?$fs/$cnt:12;
        $rowH=$fs*1.2;
        $minY=min($baselines)-$rowH;
        $maxY=max($baselines)+$rowH*0.4;

        return [
            'page'=>$page,
            'headers'=>$headers,
            'rows_assoc'=>$rowsAssoc,
            'raw_matrix'=>$final,
            'col_centers'=>$centers,
            'row_baselines'=>$baselines,
            'bbox'=>['x1'=>$minX,'y1'=>$minY,'x2'=>$maxX,'y2'=>$maxY]
        ];
    }
}