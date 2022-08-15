<?php

namespace Rulatir\Cdatify;

use DOMCdataSection;
use DOMDocument;
use DOMElement;
use DOMText;
use Eloquent\Pathogen\FileSystem\Factory\PlatformFileSystemPathFactory;
use Exception;
use Garden\Cli\Cli;
use Rulatir\Cdatify\Command\Command;

class Main
{
    public function __construct(protected Command $command) {}

    /** @throws Exception */
    public static function main(array $argv) : int
    {
        $args = static::getCLI()->parse($argv);
        return (new static(Command::make($args)))->run();
    }

    public static function getCLI() : Cli
    {
        $cli = new Cli();
        $cli
            ->description("Tweak text nodes in an XML document")
            ->opt('output:o',"Output file, by default suffixes '-<COMMAND>' to input basename (- for stdout)")
            ->arg('input', "Input file (- for stdin, default");
        $cli->command('cdata')
            ->description("Wrap XML text nodes in CDATA after decoding entities");
        $cli->command('spc')
            ->description('Replace each whitespace sequence in HTML text nodes in a <spc val=" "> </spc> tag pair')
            ->opt('remove:r', "Undo the hack from translated file", false, 'boolean');
        $cli->command('lws')
            ->description('Prepend tag-wrapped space to the inner HTML of each element that has content')
            ->opt('remove:r', "Undo the hack from translated file", false, 'boolean');
        $cli->command('clear')
            ->description('Clear target translations');
        return $cli;
    }

    public function run() : int
    {
        $inputSpec = $this->command->getInputSpec();
        $inputString = file_get_contents('-'===$inputSpec ? 'php://stdin' : $inputSpec);
        ob_start();
        $oldER = error_reporting(E_ERROR);
        echo $this->transform($inputString);
        error_reporting($oldER);
        $output = ob_get_clean();
        if ('-' === $this->command->getOutputSpec()) echo $output;
        else file_put_contents($this->command->getOutputSpec(), $output);
        return 0;
    }

    protected function transform(string $input) : string
    {
        $dom = new DOMDocument();
        $dom->loadXml($input);
        $root = $dom->documentElement;
        $this->transformElement($dom, $root);
        return $dom->saveXML();
    }

    protected function transformElement(DOMDocument $dom, DOMElement $elt) : void
    {
        foreach(iterator_to_array($elt->childNodes) as $node) {
            if ($node instanceof DOMCdataSection) {
                continue;
            }
            if ($node instanceof DOMText) {
                $elt->replaceChild($this->command->transformTextNode($dom, $elt, $node), $node);
            }
            elseif($node instanceof DOMElement) {
                $this->transformElement($dom, $node);
            }
        }
    }

    private static function buildDefaultOutputPath(string $input) : string
    {
        $pf = new PlatformFileSystemPathFactory();
        $path = $pf->create($input);
        $path = $path->replaceNamePrefix($path->namePrefix()."-CDATA");
        return $path->string();
    }
}