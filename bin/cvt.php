<?php

use Ahc\Cli\Application;
use Ahc\Cli\Input\Command as AhcCommand;
use QueryPath\QueryPath;
use Rulatir\Cdatify\QueryPath\MapTo;

require __DIR__ . '/../vendor/autoload.php';

function main(array $argv): void
{
    bootstrap();

    $app = createCLI();
    $cmd = $app->parse($argv);

    $values = $cmd->values(true);
    $pos = $cmd->args();
    $input = $values['input'] ?? ($pos[0] ?? '-');
    $inputPath = $input === '-' ? 'php://stdin' : $input;
    $output = $values['output'] ?? '-';

    $oldER = error_reporting(E_ERROR);
    ob_start();
    process($cmd, file_get_contents($inputPath));
    $result = ob_get_clean();
    error_reporting($oldER);

    if ('-' === $output) {
        echo $result;
    } else {
        @mkdir(dirname($output), 0755, true);
        file_put_contents($output, $result);
    }
}

function bootstrap(): void
{
    ini_set('memory_limit', '4G');
    QueryPath::enable(MapTo::class);
}

function process(AhcCommand $cmd, string $inputString): void
{
    echo "";

    $valuesAccessor = fn() => $cmd->values(true);
    $argsAccessor = fn() => $cmd->args();

    $getOpt = function (string $name, $default = null) use ($valuesAccessor) {
        $values = $valuesAccessor();
        $key = lcfirst(str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $name))));
        return $values[$key] ?? $default;
    };

    $getArg = function (string $name, $default = null) use ($valuesAccessor, $argsAccessor) {
        $values = $valuesAccessor();
        $key = lcfirst(str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $name))));
        if (array_key_exists($key, $values)) return $values[$key];
        $pos = $argsAccessor();
        return $pos[0] ?? $default;
    };

    echo match ($cmd->name()) {
        'html' => cmd_html(
            $inputString,
            $getOpt('long-tags', false),
            $getOpt('merge-duplicates', false),
            $getOpt('code', null),
            slugProblemsPath: $getOpt('slug-problems', null)
        ),
        'xlf' => cmd_xlf($inputString),
        'slugs' => cmd_slugs($inputString),
        'fix-slugs' => cmd_fix_slugs(
            require($getArg('input')),
            $getArg('input-lang'),
            require($getArg('source')),
            $getArg('source-lang'),
            (function () use ($getOpt) { $v = $getOpt('loose-match', []); return is_array($v) ? $v : ($v === null ? [] : [$v]); })(),
            $getOpt('problems', null)
        ),
        'improvement' => cmd_improvement($inputString, file_get_contents($getArg('better'))),
        'whtml' => cmd_whtml($inputString),
        default => 'Unknown command',
    };
}

function createCLI(): Application
{
    $app = new Application('cdatify');

    $app->command('html', 'Convert xlf to html')
        ->argument('[input]', 'Input file (- for stdin, default)')
        ->option('-o, --output [file]', 'Output file (- for stdout, default)')
        ->option('-l, --long-tags', 'Use less abbreviated tag names when transcoding shortcodes to HTML')
        ->option('-m, --merge-duplicates', 'Merge duplicate strings')
        ->option('-c, --code [file]', 'Output file for code-only (non-NL) strings as XLF')
        ->option('-s, --slug-problems [file]', 'Write slugs with words that could not be turned back into text to file (JSON)');

    $app->command('xlf', 'Convert html to xlf')
        ->argument('[input]', 'Input file (- for stdin, default)')
        ->option('-o, --output [file]', 'Output file (- for stdout, default)')
        ->option('-t, --target-locale [locale]', 'Target locale');

    $app->command('slugs', 'Generate slugs from titles')
        ->argument('[input]', 'Input file (- for stdin, default)')
        ->option('-o, --output [file]', 'Output file (- for stdout, default)');

    $app->command('fix-slugs', 'Fix slugs using entire project as translation memory')
        ->argument('[input]', 'Input mapping PHP file or path')
        ->argument('<input-lang>', 'Input file language')
        ->argument('<source>', 'Source language file - a PHP file returning an array of key-value pairs')
        ->argument('<source-lang>', 'Source language')
        ->option('-o, --output [file]', 'Output file (- for stdout, default)')
        ->option('-d, --dry-run', 'Dry run')
        ->option('-l, --loose-match [pattern]', 'Regex pattern with replacement (can be specified multiple times)')
        ->option('-p, --problems [file]', 'Write problem report to file');

    $app->command('improvement', 'Produce a .xlf that only contains translation units whose translations or sources have changed')
        ->argument('<better>', 'Changed xlf file')
        ->option('-o, --output [file]', 'Output file (- for stdout, default)');

    $app->command('whtml', 'Put a Loco HTML export into form that can be easily converted into a word metrics document')
        ->argument('[input]', 'Input file (- for stdin, default)')
        ->option('-o, --output [file]', 'Output file (- for stdout, default)');

    return $app;
}

main($argv);
