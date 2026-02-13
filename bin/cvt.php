<?php

use Garden\Cli\Args;
use Garden\Cli\Cli;
use QueryPath\QueryPath;
use Rulatir\Cdatify\QueryPath\MapTo;

require __DIR__."/../vendor/autoload.php";

function main(array $argv) : void
{
    bootstrap();
    $args = createCLI()->parse($argv, true);
    $input = $args->getArg('input', '-');
    if ($input==='-') $input = 'php://stdin';
    $output = $args->getOpt('output','-');
    $oldER = error_reporting(E_ERROR);
    ob_start();
    process($args, file_get_contents($input));
    $result = ob_get_clean();
    error_reporting($oldER);
    if ('-'===$output) echo $result;
    else {
        @mkdir(dirname($output),0755,true);
        file_put_contents($output, $result);
    }
}

function bootstrap() : void
{
    ini_set('memory_limit','4G');
    QueryPath::enable(MapTo::class);

}

function process(Args $args, string $inputString) : void
{
    echo "";
    echo match ($args->getCommand()) {
        "html"=>cmd_html($inputString, $args->getOpt('long-tags',false), $args->getOpt('merge-duplicates',false)),
        "xlf"=>cmd_xlf($inputString),
        "slugs"=>cmd_slugs($inputString),
        "fix-slugs"=>cmd_fix_slugs(
            require($args->getArg('input')),
            $args->getArg('input-lang'),
            require($args->getArg('source')),
            $args->getArg('source-lang'),
            $args->getOpt('loose-match', []),
            $args->getOpt('problems', null)
        ),
        "improvement"=>cmd_improvement($inputString, file_get_contents($args->getArg("better"))),
        "whtml"=>cmd_whtml($inputString),
        default=>"Unknown command"
    };
}

function createCLI() : Cli
{
    $cli = new Cli;
    $cli->description("Convert xlf to html and back for Amazon Translate")
        ->arg('input','Input file (- for stdin, default)')
        ->opt('output:o','Output file (- for stdout, default)');

    $cli->command('html')->description('Convert xlf to html')
        ->opt('long-tags:l','Use less abbreviated tag names when transcoding shortcodes to HTML',type: 'bool')
        ->opt('merge-duplicates:m', 'Merge duplicate strings',type: 'bool');

    $cli->command('xlf')
        ->description('Convert html to xlf')
        ->opt('target-locale:t', 'Target locale');

    $cli->command('slugs')->description('Generate slugs from titles');

    $cli->command('fix-slugs')
        ->description('Fix slugs using entire project as translation memory')
        ->arg('input-lang','Input file language')
        ->arg('source','Source language file - a PHP file returning an array of key-value pairs')
        ->arg('source-lang','Source language')
        ->opt('dry-run:d','Dry run',type: 'bool')
        ->opt('loose-match:l','Regex pattern with replacement (e.g., /^(#?)(?P<match>.*)(-all-\\d+)$/$1$T$2/). Named group "match" is compared, $T becomes target slug. Strict match is tried first. Include (#?) and $1 if you want hash preservation. Can be specified multiple times, first match wins.',type: 'string[]')
        ->opt('problems:p','Write problem report to file');

    $cli->command('improvement')
        ->description('Produce a .xlf that only contains translation units whose translations or sources have changed')
        ->arg('better', "Changed xlf file");

    $cli->command('whtml')->description(
        "Put a Loco HTML export into form that can be easily converted into a word metrics document"
    );

    return $cli;
}

main($argv);
