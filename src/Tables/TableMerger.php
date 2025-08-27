<?php
namespace ParserPDF\Tables;

use ParserPDF\Core\Context;
use ParserPDF\Util\Geo;
use ParserPDF\Util\Log;

class TableMerger
{
    public static function merge(Context $ctx, array $tables): array
    {
        if (!empty($ctx->options['disable_text_tables'])) {
            $tables = array_values(array_filter($tables, fn($t)=>$t['origin']==='line'));
            Log::d($ctx,"После merge (disable_text_tables) осталось: ".count($tables));
            return $tables;
        }

        $preferLine = (bool)($ctx->options['prefer_line_tables'] ?? true);
        if (!$preferLine) return $tables;

        $containRatio          = (float)($ctx->options['merge_containment_ratio']           ?? 0.9);
        $dropIfContains        = (bool) ($ctx->options['merge_drop_text_if_line_contains']  ?? true);
        $minColAdvContain      = (int)  ($ctx->options['merge_line_min_columns_advantage']  ?? 0);
        $dropIfMoreColumns     = (bool) ($ctx->options['merge_drop_text_if_line_more_columns'] ?? true);
        $minColAdvantageExtra  = (int)  ($ctx->options['merge_line_min_columns_advantage']  ?? 1);
        $horizCoverThreshold   = (float)($ctx->options['merge_horizontal_cover_threshold']  ?? 0.9);
        $vertOverlapThreshold  = (float)($ctx->options['merge_vertical_overlap_threshold']  ?? 0.5);
        $iouMin                = (float)($ctx->options['merge_iou_min']                     ?? 0.3);

        $dropNestedTextTables  = (bool)($ctx->options['merge_drop_nested_text_tables'] ?? true);
        $nestedAreaRatio       = (float)($ctx->options['nested_text_table_area_ratio'] ?? 0.8);
        $nestedRowOverlap      = (float)($ctx->options['nested_text_table_row_overlap_ratio'] ?? 0.5);

        $lineTables = array_filter($tables, fn($t)=>$t['origin']==='line');
        if (!$lineTables) return $tables;

        $filtered=[];
        foreach ($tables as $t){
            if ($t['origin']==='text'){
                $keep=true;
                $tCols = count($t['headers']);
                foreach ($lineTables as $lt){
                    if ($lt['page'] !== $t['page']) continue;

                    $ltCols = count($lt['headers']);
                    $iou    = Geo::iou($t['bbox'], $lt['bbox']);
                    $contain = self::containment($t['bbox'], $lt['bbox']);
                    [$hCover,$vOverlap] = self::overlapsHV($t['bbox'],$lt['bbox']);

                    if ($dropIfContains && $contain >= $containRatio && ($ltCols - $tCols) >= $minColAdvContain){
                        $keep=false; break;
                    }

                    if ($dropIfMoreColumns &&
                        ($ltCols - $tCols) >= $minColAdvantageExtra &&
                        $hCover >= $horizCoverThreshold &&
                        $vOverlap >= $vertOverlapThreshold){
                        $keep=false; break;
                    }
                    
                    if ($dropIfMoreColumns && ($ltCols > $tCols) && $iou >= $iouMin){
                        $keep=false; break;
                    }
                    
                    if ($dropNestedTextTables &&
                        $contain >= $nestedAreaRatio &&
                        self::rowOverlap($t, $lt) >= $nestedRowOverlap
                    ){
                        $keep=false; break;
                    }
                }
                
                if ($keep) $filtered[]=$t;
            } else {
                $filtered[]=$t;
            }
        }
        Log::d($ctx,"После merge таблиц: ".count($filtered));
        return $filtered;
    }

    private static function containment(array $inner, array $outer): float
    {
        $ix1 = max($inner['x1'],$outer['x1']);
        $iy1 = max($inner['y1'],$outer['y1']);
        $ix2 = min($inner['x2'],$outer['x2']);
        $iy2 = min($inner['y2'],$outer['y2']);
        $iw = max(0,$ix2-$ix1);
        $ih = max(0,$iy2-$iy1);
        $inter=$iw*$ih;
        $areaInner = ($inner['x2']-$inner['x1'])*($inner['y2']-$inner['y1']);
        return $areaInner>0 ? $inter/$areaInner : 0;
    }

    private static function overlapsHV(array $textBox, array $lineBox): array
    {
        $tw = max(0, $textBox['x2'] - $textBox['x1']);
        $th = max(0, $textBox['y2'] - $textBox['y1']);
        $ix1 = max($textBox['x1'],$lineBox['x1']);
        $ix2 = min($textBox['x2'],$lineBox['x2']);
        $iw = max(0,$ix2-$ix1);
        $iy1 = max($textBox['y1'],$lineBox['y1']);
        $iy2 = min($textBox['y2'],$lineBox['y2']);
        $ih = max(0,$iy2-$iy1);
        $hCover = $tw? $iw/$tw : 0;
        $vOverlap = $th? $ih/$th : 0;
        return [$hCover,$vOverlap];
    }

    private static function rowOverlap(array $textTable, array $lineTable): float
    {
        // берем вертикальное пересечение baseline/ bbox
        $tb = $textTable['bbox'];
        $lb = $lineTable['bbox'];
        $ix1 = max($tb['x1'],$lb['x1']); // не принципиально, важно вертикальное
        $iy1 = max($tb['y1'],$lb['y1']);
        $ix2 = min($tb['x2'],$lb['x2']);
        $iy2 = min($tb['y2'],$lb['y2']);
        $ih = max(0,$iy2-$iy1);
        $th = max(1e-6, $tb['y2']-$tb['y1']);
        return $ih / $th;
    }
}