<?php
$composerAutoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($composerAutoload)) {
    require $composerAutoload;
} else {
    spl_autoload_register(function(string $cls){
        $prefix = 'ParserPDF\\';
        if (strncmp($cls, $prefix, strlen($prefix)) !== 0) return;
        $relative = substr($cls, strlen($prefix));
        $path = __DIR__ . '/../src/' . str_replace('\\','/',$relative) . '.php';
        if (is_file($path)) {
            require $path;
        }
    });
}


use ParserPDF\ParserPDF;

$args = $argv;
array_shift($args);

$file        = null;
$options     = [];
$outFile     = null;
$save        = true;
$printStdout = true;

foreach ($args as $arg) {
    if (str_starts_with($arg,'--file=')) {
        $file = substr($arg,7);
    } elseif ($arg === '--debug') {
        $options['debug'] = true;
    } elseif ($arg === '--no-save') {
        $save = false;
    } elseif ($arg === '--no-stdout') {
        $printStdout = false;
    } elseif (str_starts_with($arg,'--out=')) {
        $outFile = substr($arg,6);
    } elseif (str_starts_with($arg,'--')) {
        [$k,$v] = array_pad(explode('=',$arg,2),2,null);
        $k = ltrim($k,'-');
        if ($v === null) $v = true;
        if (is_numeric($v) && preg_match('/^-?\d+(\.\d+)?$/', $v)) {
            $v = (float)$v;
        }
        $options[$k] = $v;
    } elseif ($file === null && is_file($arg)) {
        $file = $arg;
    }
}

if (!$file) {
    fwrite(STDERR,"Usage: php bin/run.php --file=your.pdf [--debug] [--no-save] [--no-stdout] [--out=result.json] [...]\n");
    exit(1);
}
if (!is_file($file)) {
    fwrite(STDERR,"Файл не найден: $file\n");
    exit(1);
}

if ($outFile === null) {
    $dir  = rtrim(dirname($file), DIRECTORY_SEPARATOR);
    $base = basename($file);
    $jsonName = preg_replace('/\.pdf$/i', '.json', $base);
    if ($jsonName === $base) {
        $jsonName .= '.json';
    }
    $outFile = $dir . DIRECTORY_SEPARATOR . $jsonName;
}

try {
    $parser = new ParserPDF($file, $options);
    $json   = $parser->toJSON();

    if ($save) {
        $outDir = dirname($outFile);
        if (!is_dir($outDir) && !mkdir($outDir, 0777, true) && !is_dir($outDir)) {
            throw new RuntimeException("Не удалось создать директорию для вывода: $outDir");
        }
        file_put_contents($outFile, $json);
        if ($printStdout) {
            echo $json, PHP_EOL;
            fwrite(STDERR, "[ParserPDF] JSON сохранён: $outFile\n");
        } else {
            fwrite(STDERR, "[ParserPDF] JSON сохранён (stdout отключён): $outFile\n");
        }
    } else {
        if ($printStdout) {
            echo $json, PHP_EOL;
        } else {
            fwrite(STDERR,"[ParserPDF] (--no-save) и (--no-stdout) — ничего не выведено.\n");
        }
    }

} catch (Throwable $e) {
    fwrite(STDERR,"Ошибка: ".$e->getMessage().PHP_EOL);
    if (!empty($options['debug'])) {
        fwrite(STDERR,$e->getTraceAsString().PHP_EOL);
    }
    exit(1);
}