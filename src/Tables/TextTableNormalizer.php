<?php
namespace ParserPDF\Tables;

use ParserPDF\Core\Context;
use ParserPDF\Util\Value;

class TextTableNormalizer
{
    public static function normalize(Context $ctx, array $tables): array
    {
        $enabled = !isset($ctx->options['text_table_normalize']) || (bool)$ctx->options['text_table_normalize'];
        if (!$enabled) return $tables;

        foreach ($tables as &$t) {
            if (($t['origin'] ?? '') !== 'text') continue;
            if (empty($t['raw_matrix']) || count($t['raw_matrix']) < 2) continue;

            $raw = $t['raw_matrix'];
            $headerRow = $raw[0];
            $dataRows = array_slice($raw,1);
            $colCount = count($headerRow);
            if ($colCount === 0) continue;

            $valueCount = [];
            for ($j=0; $j<$colCount; $j++){
                $cnt = 0;
                foreach ($dataRows as $row){
                    if (!isset($row[$j])) continue;
                    if (trim((string)$row[$j]) !== '') $cnt++;
                }
                $valueCount[$j] = $cnt;
            }

            $dataCols = [];
            for ($j=0;$j<$colCount;$j++){
                if ($valueCount[$j] > 0) $dataCols[] = $j;
            }
            if (count($dataCols) === 0) continue;

            $usedHeaderTexts = [];
            $colMap = [];
            $nextAutoLetterOrd = ord('a');

            foreach ($dataCols as $j) {
                $header = trim((string)($headerRow[$j] ?? ''));

                if ($header === '') {
                    $shifted = '';
                    if ($j+1 < $colCount) {
                        $candidate = trim((string)$headerRow[$j+1]);
                        if ($candidate !== '' && $valueCount[$j+1] === 0) {
                            $shifted = $candidate;
                        }
                    }
                    if ($shifted !== '') {
                        $header = $shifted;
                    }
                }

                if ($header === '') {
                    do {
                        $header = chr($nextAutoLetterOrd);
                        $nextAutoLetterOrd++;
                    } while (isset($usedHeaderTexts[$header]));
                }

                $base = $header; $suffix = 2;
                while (isset($usedHeaderTexts[$header])) {
                    $header = $base . '_' . $suffix;
                    $suffix++;
                }
                $usedHeaderTexts[$header] = true;
                $colMap[$j] = $header;
            }

            $newHeaders = [];
            foreach ($dataCols as $j) {
                $newHeaders[] = $colMap[$j];
            }

            $newMatrix = [];
            $newMatrix[] = $newHeaders;
            foreach ($dataRows as $row) {
                $newRow = [];
                foreach ($dataCols as $j) {
                    $newRow[] = $row[$j] ?? '';
                }
                $newMatrix[] = $newRow;
            }

            $newRowsAssoc = [];
            foreach ($dataRows as $row) {
                $assoc = [];
                foreach ($dataCols as $jIndex => $oldJ) {
                    $h = $newHeaders[$jIndex];
                    $val = $row[$oldJ] ?? '';
                    $assoc[$h] = Value::cast($val);
                }
                $newRowsAssoc[] = $assoc;
            }

            $centers = $t['col_centers'] ?? $t['params']['column_centers'] ?? null;
            $newCenters = null;
            if (is_array($centers)) {
                $newCenters = [];
                foreach ($dataCols as $oldJ) {
                    $newCenters[] = $centers[$oldJ] ?? null;
                }
            }

            $t['headers'] = $newHeaders;
            $t['rows_assoc'] = $newRowsAssoc;
            $t['raw_matrix'] = $newMatrix;
            if ($newCenters !== null) {
                $t['col_centers'] = $newCenters;
                if (isset($t['params']['column_centers'])) {
                    $t['params']['column_centers'] = $newCenters;
                }
            }
        }
        unset($t);
        return $tables;
    }
}