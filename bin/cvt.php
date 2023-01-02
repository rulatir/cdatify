<?php

use Garden\Cli\Args;
use Garden\Cli\Cli;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Rulatir\Cdatify\Shortcode\AskerOfQuestionsCLI;
use Rulatir\Cdatify\Shortcode\ParameterTranslationDeciderAnswerFile;
use Rulatir\Cdatify\Shortcode\ShortcodeConverter;
use Rulatir\Cdatify\Utility as Util;

require __DIR__."/../vendor/autoload.php";

function main(array $argv) : void
{
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
    else file_put_contents($output, $result);
}

function process(Args $args, string $inputString) : void
{
    echo match ($args->getCommand()) {
        "html"=>cmd_html($inputString),
        "xlf"=>cmd_xlf($inputString),
        default=>"Unknown command"
    };
}

function makeShortcodeConverter() : ShortcodeConverter
{
    $configDir = getenv('HOME')."/.local/cdatify/shortcode-schemas";
    @mkdir($configDir, 0755, true);
    return new ShortcodeConverter(
        new ParameterTranslationDeciderAnswerFile(
            'zuu-cms-shortcodes',
            new Filesystem(new LocalFilesystemAdapter($configDir)),
            new AskerOfQuestionsCLI()
        )
    );
}

function createCLI() : Cli
{
    $cli = new Cli;
    $cli->description("Convert xlf to html for Amazon Translate")
        ->arg('input','Input file (- for stdin, default)')
        ->opt('output:o','Output file (- for stdout, default)');

    $cli->command('html')->description('Convert xlf to html');
    $cli->command('xlf')->description('Convert html to xlf');
    return $cli;
}

function getParentElement(DOMElement $elt) : ?DOMElement {
    $parent = $elt->parentNode;
    return $parent instanceof DOMElement ? $parent : null;
}

function cmd_html(string $inputString) : string
{
    $dom = new DOMDocument('1.0','UTF-8');
    $dom->loadXML($inputString);
    $result = [];
    $items = iterator_to_array($dom->getElementsByTagName('source'));
    $file = $dom->getElementsByTagName('file')[0];
    $original = $file->getAttribute('original');
    $sourceLangauge = $file->getAttribute('source-langauge');
    $targetLanguage = $file->getAttribute('target-language');
    $shortcodeConverter = makeShortcodeConverter();
    array_walk(
        $items,
        function(DOMElement $src) use($shortcodeConverter, &$result) {
            if ($transUnit = getParentElement($src)) {
                $result[] = [
                    'id' => $transUnit->getAttribute('id'),
                    'resname' => $transUnit->getAttribute('resname'),
                    'value' => $shortcodeConverter->sc2html($src->textContent)
                ];
            }
        }
    );
    /** @noinspection PhpParamsInspection */
    return renderTemplate(...[...htmlTemplate([
        '%%%original%%%' => $original,
        '%%%source-language%%%' => $sourceLangauge,
        '%%%target-language%%%' => $targetLanguage
    ]), $result]);
}

function cmd_xlf(string $inputString) : string
{
    $dom = new DOMDocument('1.0','UTF-8');
    $shortcodeConverter = makeShortcodeConverter();
    $inputString = $shortcodeConverter->html2sc($inputString);
    $dom->loadHTML($inputString);
    $result = [];
    $items = array_filter(
        iterator_to_array($dom->getElementById('the-body')->childNodes),
        fn(DOMNode $child) : bool => $child instanceof DOMElement && $child->tagName==='section'
    );
    $body = $dom->getElementById('the-body');
    $original = $body->getAttribute('data-original');
    $sourceLangauge = $body->getAttribute('data-source-langauge');
    $targetLanguage = $body->getAttribute('data-target-language');
    array_walk(
        $items,
        function(DOMElement $section) use(&$result) {
            $result[] = [
                'id' => $section->getAttribute('data-loco-id'),
                'resname' => $section->getAttribute('data-resname'),
                'value' => Util::numeric_entities(htmlentities(Util::innerHTML($section)))
            ];
        }
    );
    /** @noinspection PhpParamsInspection */
    return renderTemplate(...[...xlfTemplate([
        '%%%original%%%' => $original,
        '%%%source-language%%%' => $sourceLangauge,
        '%%%target-language%%%' => $targetLanguage
    ]), $result]);
}

function renderTemplate(string $docTpl, string $itemTpl, array $items) : string
{
    $renderedItems = array_map(
        fn(array $item) : string => str_replace(
            array_map(fn($k) => "%%%$k%%%", array_keys($item)),
            array_values($item),
            $itemTpl
        ),
        $items
    );
    return str_replace('%%%ITEMS%%%',implode("\n",$renderedItems),$docTpl);
}

function htmlTemplate(array $substitutions=[]) : array
{
    $html = <<<HTML
<!DOCTYPE html>
<html lang="pl">
	<head>
		<title translate="no">String catalog</title>
		<meta charset="UTF-8">
	</head>
	<body
	    id="the-body"
	    data-original="%%%original%%%"
	    data-source-language="%%%source-language%%%"
	    data-target-language="%%%target-language%%%"
    >
		%%%ITEMS%%%
	</body>
</html>
HTML;

    $item=<<<ITEM
        <section data-loco-id="%%%id%%%" data-resname="%%%resname%%%">%%%value%%%</section>
ITEM;
    return [str_replace(array_keys($substitutions), array_values($substitutions), $html), $item];
}

function xlfTemplate(array $substitutions): array
{
    $xlf = <<<XLF
<?xml version="1.0" encoding="UTF-8"?>
<xliff xmlns="urn:oasis:names:tc:xliff:document:1.2" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" version="1.2" xsi:schemaLocation="urn:oasis:names:tc:xliff:document:1.2 http://docs.oasis-open.org/xliff/v1.2/os/xliff-core-1.2-strict.xsd">
  <file original="%%%original%%%" source-language="%%%source-language%%%" target-language="%%%target-language%%%" datatype="database" tool-id="loco">
    <header>
    <tool tool-id="loco" tool-name="Loco" tool-version="1.0.26 20220711-1" tool-company="Loco"></tool></header>
    <body>
      %%%ITEMS%%%
    </body>
  </file>
</xliff>
XLF;
    $item = <<<ITEM
      <trans-unit id="%%%id%%%" resname="%%%resname%%%" datatype="plaintext">
        <source/>
        <target>%%%value%%%</target>
      </trans-unit>
ITEM;

    return [str_replace(array_keys($substitutions), array_values($substitutions), $xlf), $item];
}

main($argv);
