<?php
declare(strict_types=1);

namespace ParserPDF\Util;

class Value
{
    public static function cast(mixed $value, array $options = [])
    {
        if ($value === null) return '';
        if (is_int($value) || is_float($value)) return $value;
        $s = trim((string)$value);
        if ($s === '') return '';

        if (!empty($options['disable_casting'])) {
            return $s;
        }

        if (preg_match('/^[+-]?\d+$/', $s)) {
            $signless = ltrim($s, '+-');
            if ($signless === '0') return (int)$s;
            if ($signless[0] === '0') return $s;
            if (strlen($signless) <= 18) return (int)$s;
            return $s;
        }

        if (preg_match('/^[+-]?(\d+)[\.,](\d+)$/', $s)) {
            $digits = preg_replace('/[+,\-\.]/','',$s);
            if (strlen($digits) > 30) return $s;
            return (float)str_replace(',', '.', $s);
        }

        return $s;
    }
}