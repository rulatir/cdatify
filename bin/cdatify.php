<?php use Rulatir\Cdatify\Main;

require __DIR__."/../vendor/autoload.php";

function traceNoArgs(array $trace) : string
{
    return implode("\n", array_map(
        fn($frame) => "{$frame['file']}:{$frame['line']}",
        $trace
    ));
}

try {
    exit(Main::main($argv));
} catch (Exception $e) {
    echo "At {$e->getFile()}:{$e->getLine()}:\n{$e->getMessage()}\n".traceNoArgs($e->getTrace());
    exit(1);
}

