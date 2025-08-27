<?php
namespace ParserPDF\Tables;

use ParserPDF\Util\Value;

class TableStructureNormalizer
{
    public static function normalize(array $table): array
    {
        if (empty($table['raw_matrix']) || empty($table['headers'])) {
            return $table;
        }

        $headers   = $table['headers'];
        $rawMatrix = $table['raw_matrix'];
        $dataRows  = array_slice($rawMatrix, 1);
        $colCount  = count($headers);
        if ($colCount === 0) return $table;

        $cols = [];
        for ($c=0; $c<$colCount; $c++){
            $vals=[];
            foreach ($dataRows as $r){
                $vals[] = $r[$c] ?? '';
            }
            $cols[]=[
                'header'=>$headers[$c],
                'values'=>$vals,
                '_orig'=>$c
            ];
        }

        $isPlaceholder = fn(string $h) => ($h==='' || preg_match('/^col_\d+$/',$h));

        $allLeftFilledRightEmpty = function(array $left,array $right): bool {
            $n=count($left);
            for ($i=0;$i<$n;$i++){
                if (trim((string)$left[$i])==='' || trim((string)$right[$i])!=='') return false;
            }
            return true;
        };
        $allLeftEmptyRightFilled = function(array $left,array $right): bool {
            $n=count($left);
            for ($i=0;$i<$n;$i++){
                if (trim((string)$left[$i])!=='' || trim((string)$right[$i])==='') return false;
            }
            return true;
        };

        $changed=true;
        while($changed){
            $changed=false;

            for($i=0;$i<count($cols)-1;$i++){
                $A=&$cols[$i]; $B=&$cols[$i+1];
                if ($isPlaceholder($A['header']) && !$isPlaceholder($B['header'])
                    && $allLeftFilledRightEmpty($A['values'],$B['values'])){
                    for($r=0;$r<count($A['values']);$r++){
                        $B['values'][$r]=$A['values'][$r];
                        $A['values'][$r]='';
                    }
                    array_splice($cols,$i,1);
                    $changed=true; break;
                }
            }
            if ($changed) continue;

            for($i=0;$i<count($cols)-1;$i++){
                $A=&$cols[$i]; $B=&$cols[$i+1];
                if (!$isPlaceholder($A['header']) && $isPlaceholder($B['header'])
                    && $allLeftEmptyRightFilled($A['values'],$B['values'])){
                    for($r=0;$r<count($A['values']);$r++){
                        $A['values'][$r]=$B['values'][$r];
                        $B['values'][$r]='';
                    }
                    array_splice($cols,$i+1,1);
                    $changed=true; break;
                }
            }
            if ($changed) continue;

            for($i=0;$i<count($cols)-1;$i++){
                $A=&$cols[$i]; $B=&$cols[$i+1];
                if ($isPlaceholder($A['header']) && !$isPlaceholder($B['header'])
                    && $allLeftFilledRightEmpty($A['values'],$B['values'])){
                    for($r=0;$r<count($A['values']);$r++){
                        $B['values'][$r]=$A['values'][$r];
                        $A['values'][$r]='';
                    }
                    array_splice($cols,$i,1);
                    $changed=true; break;
                }
            }
            if ($changed) continue;

            for($i=0;$i<count($cols)-1;$i++){
                $hA=trim((string)$cols[$i]['header']);
                $hB=trim((string)$cols[$i+1]['header']);
                if ($hA!=='' && $hA===$hB){
                    $A=&$cols[$i]; $B=&$cols[$i+1];
                    for($r=0;$r<count($A['values']);$r++){
                        if (trim((string)$A['values'][$r])===''){
                            $A['values'][$r]=$B['values'][$r];
                        }
                    }
                    array_splice($cols,$i+1,1);
                    $changed=true; break;
                }
            }
        }

        $seen=[]; $tmp=[];
        foreach ($cols as $col){
            $h=trim((string)$col['header']);
            if ($h==='' || $isPlaceholder($h)){
                $tmp[]=$col;
                continue;
            }
            if (!isset($seen[$h])){
                $seen[$h]=&$col;
                $tmp[]=$col;
            } else {
                for($r=0;$r<count($col['values']);$r++){
                    if (trim((string)$seen[$h]['values'][$r])===''){
                        $seen[$h]['values'][$r]=$col['values'][$r];
                    }
                }
            }
        }
        $cols=$tmp;

        for ($i=0;$i<count($cols);$i++){
            $h=trim((string)$cols[$i]['header']);
            if (!$isPlaceholder($h)) continue;
            $targetIdx=null;
            for($j=$i+1;$j<count($cols);$j++){
                $h2=trim((string)$cols[$j]['header']);
                if (!$isPlaceholder($h2)){
                    $targetIdx=$j; break;
                }
            }
            if ($targetIdx===null) continue;
            $src=&$cols[$i]; $dst=&$cols[$targetIdx];
            for($r=0;$r<count($src['values']);$r++){
                if (trim((string)$src['values'][$r])!=='' && trim((string)$dst['values'][$r])===''){
                    $dst['values'][$r]=$src['values'][$r];
                    $src['values'][$r]='';
                }
            }
        }

        $cols = array_values(array_filter($cols, fn($c)=>!$isPlaceholder(trim((string)$c['header']))));

        if (!$cols){
            $table['headers']=[];
            $table['rows_assoc']=[];
            $table['raw_matrix']=[[]];
            return $table;
        }

        $newHeaders = array_map(fn($c)=>$c['header'],$cols);
        $rowCount = count($cols[0]['values']);
        $newDataRows=[];
        for($r=0;$r<$rowCount;$r++){
            $row=[];
            foreach ($cols as $c){
                $row[]=$c['values'][$r];
            }
            $newDataRows[]=$row;
        }
        $newRawMatrix = array_merge([$newHeaders], $newDataRows);

        $rowsAssoc=[];
        foreach ($newDataRows as $r){
            $assoc=[];
            foreach ($r as $i=>$val){
                $assoc[$newHeaders[$i]] = Value::cast($val);
            }
            $rowsAssoc[]=$assoc;
        }

        $suffixDrop = [];
        $headerIndex = array_flip($newHeaders);
        for ($i=0;$i<count($newHeaders);$i++){
            $h=$newHeaders[$i];
            if (preg_match('/^(.*)_([0-9]+)$/',$h,$m)){
                $base = $m[1];
                if (!isset($headerIndex[$base])) continue;
                $allEmpty=true;
                foreach ($newDataRows as $row){
                    if (isset($row[$i]) && trim((string)$row[$i])!==''){
                        $allEmpty=false; break;
                    }
                }
                if ($allEmpty){
                    $suffixDrop[$i]=true;
                }
            }
        }
        if ($suffixDrop){
            $keepHeaders=[]; $map=[];
            foreach ($newHeaders as $i=>$h){
                if (isset($suffixDrop[$i])) continue;
                $map[$i]=count($keepHeaders);
                $keepHeaders[]=$h;
            }
            $keepRows=[];
            foreach ($newDataRows as $row){
                $nr=[];
                foreach ($row as $i=>$v){
                    if (isset($suffixDrop[$i])) continue;
                    $nr[]=$v;
                }
                $keepRows[]=$nr;
            }
            $newHeaders=$keepHeaders;
            $newDataRows=$keepRows;
            $newRawMatrix = array_merge([$newHeaders], $newDataRows);

            $rowsAssoc=[];
            foreach ($newDataRows as $r){
                $assoc=[];
                foreach ($r as $i=>$val){
                    $assoc[$newHeaders[$i]] = Value::cast($val);
                }
                $rowsAssoc[]=$assoc;
            }

            $rebuildCenters = function($centers) use ($suffixDrop){
                if (!$centers) return $centers;
                $nc=[];
                foreach ($centers as $i=>$cVal){
                    if (isset($suffixDrop[$i])) continue;
                    $nc[]=$cVal;
                }
                return $nc;
            };

            if (!empty($table['col_centers'])){
                $table['col_centers']=$rebuildCenters($table['col_centers']);
            }
            if (!empty($table['params']['column_centers'])){
                $table['params']['column_centers']=$rebuildCenters($table['params']['column_centers']);
            }

            if (!empty($table['cells'])){
                $cells=[];
                $hdr=[];
                foreach ($newHeaders as $ci=>$h){
                    $hdr[]=[
                        'row_type'=>'header',
                        'col'=>$ci,
                        'header_name'=>$h,
                        'text'=>$h,
                        'bbox'=>null
                    ];
                }
                $cells[]=$hdr;
                foreach ($newDataRows as $ri=>$row){
                    $rc=[];
                    foreach ($row as $ci=>$val){
                        $rc[]=[
                            'row_type'=>'data',
                            'row_index'=>$ri,
                            'col'=>$ci,
                            'header_name'=>$newHeaders[$ci],
                            'text'=>$val,
                            'bbox'=>null
                        ];
                    }
                    $cells[]=$rc;
                }
                $table['cells']=$cells;
            }
        }

        if (empty($suffixDrop)){
            $oldCenters = $table['col_centers'] ?? ($table['params']['column_centers'] ?? []);
            $newCenters=[];
            for ($i=0;$i<count($newHeaders);$i++){
                $newCenters[]=$oldCenters[$i] ?? null;
            }
            $table['col_centers']=$newCenters;
            if (isset($table['params']['column_centers'])){
                $table['params']['column_centers']=$newCenters;
            }
        }

        $table['headers']=$newHeaders;
        $table['rows_assoc']=$rowsAssoc;
        $table['raw_matrix']=$newRawMatrix;
        if (isset($table['params']['raw_matrix'])) $table['params']['raw_matrix']=$newRawMatrix;
        if (isset($table['params']['headers']))    $table['params']['headers']=$newHeaders;

        return $table;
    }

    public static function normalizeMany(array $tables): array
    {
        foreach ($tables as &$t){
            if (!empty($t['headers'])){
                $t=self::normalize($t);
            }
        }
        unset($t);
        return $tables;
    }
}