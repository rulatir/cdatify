<?php

use ArteQ\CSX\Xliff\Xliff;
use Cocur\Slugify\Slugify;
use DOMWrap\Document;
use DOMWrap\Element;
use EMS\Xliff\Xliff\Extractor;
use Garden\Cli\Args;
use Garden\Cli\Cli;
use Matecat\XliffParser\Exception\InvalidXmlException;
use Matecat\XliffParser\Exception\NotSupportedVersionException;
use Matecat\XliffParser\Exception\NotValidFileException;
use Matecat\XliffParser\Exception\XmlParsingException;
use Matecat\XliffParser\XliffParser;
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
        "html"=>cmd_html($inputString, $args->getOpt('long-tags',false), $args->getOpt('merge-duplicates',false)),
        "xlf"=>cmd_xlf($inputString),
        "slugs"=>cmd_slugs($inputString),
        default=>"Unknown command"
    };
}

function createCLI() : Cli
{
    $cli = new Cli;
    $cli->description("Convert xlf to html and back for Amazon Translate")
        ->arg('input','Input file (- for stdin, default)')
        ->opt('output:o','Output file (- for stdout, default)')
        ->opt('long-tags:l','Use less abbreviated tag names when transcoding shortcodes to HTML',type: 'bool')
        ->opt('merge-duplicates:m', 'Merge duplicate strings',type: 'bool');


    $cli->command('html')->description('Convert xlf to html');
    $cli->command('xlf')->description('Convert html to xlf');
    $cli->command('slugs')->description('Generate slugs from titles');
    return $cli;
}

function getParentElement(DOMElement $elt) : ?DOMElement {
    $parent = $elt->parentNode;
    return $parent instanceof DOMElement ? $parent : null;
}

function cmd_html(string $inputString, bool $useLongTags, bool $mergeDuplicates) : string
{
    $parser = new XliffParser();
    $data = $parser->xliffToArray($inputString);

    $files = array_values($data['files']);

    [
        'original'=> $original,
        'source-language' => $sourceLanguage,
        'target-language'=>$targetLanguage,
    ]
        = $files[0]['attr'];

    $shortcodeConverter = makeShortcodeConverter($useLongTags);

    $result = array_map(
        fn($unit) => [
            'id' => $unit['attr']['id'],
            'resname' => $unit['attr']['resname'],
            'value' => $shortcodeConverter->sc2html(htmlspecialchars_decode($unit['source']['raw-content']))
        ],
        array_merge(...array_map(fn($f) => array_values($f['trans-units']),$files))
    );

    $templateParams = [
        '%%%original%%%' => $original,
        '%%%source-language%%%' => $sourceLanguage,
        '%%%target-language%%%' => $targetLanguage,
        '%%%use-long-tags%%%' => $useLongTags ? "yes" : "no"
    ];
    /** @noinspection PhpParamsInspection */
    return
        $mergeDuplicates
        ? renderTemplateWithDeduplication(...[...htmlTemplateWithDeduplication($templateParams), mergeDuplicates($result)])
        : renderTemplate(...[...htmlTemplate($templateParams), $result]);
}

function mergeDuplicates(array $items) : array
{
    $merged = [];
    foreach($items as $item) {
        $key = md5($v=$item['value']);
        $merged[$key] ??= [
            'IDS' => [],
            'value' => $v
        ];
        $merged[$key]['IDS'][]=['id'=>$item['id'],'resname'=>$item['resname']];
    }
    return $merged;
}

function splitDuplicates(array $items) : array
{
    $split=[];
    foreach($items as $item) {
        foreach($item['IDS'] as $id) {
            $split = [
                ...$id,
                'value'=>$item['value']
            ];
        }
    }
    return $split;
}

function cmd_slugs(string $inputString) : string
{
    $dom = new DOMDocument('1.0','UTF-8');
    $dom->loadXML($inputString);
    $slugSources = ['.slug','.title','.navLabel'];
    $elements=[];
    foreach($slugSources as $slugSourceKey) $elements[$slugSourceKey]=[];
    $regexp = '/('.implode("|",array_map('preg_quote',$slugSources)).')$/';
    foreach($dom->getElementsByTagName('target') as $targetElement) {
        $transUnit = getParentElement($targetElement);
        $resName = $transUnit->getAttribute('resname');
        $matches=[];
        if(preg_match($regexp,$resName,$matches)) {
            $elements[$matches[1]][preg_replace($regexp,'',$resName)]=$targetElement;
        }
    }
    $file = $dom->getElementsByTagName('file')[0];
    $slugifier = makeSlugifier($file->getAttribute('target-language'));
    /** @var DOMElement $slugElement */
    foreach($elements['.slug'] as $resKey => $slugElement) {
        $slugSource=$slugElement;
        foreach($slugSources as $slugSourceKey) if ($slugSource = $elements[$slugSourceKey][$resKey]) break;
        $slugElement->textContent=$slugifier->slugify($slugSource->textContent);
    }
    return $dom->saveXML();
}

function makeSlugifier(string $targetLanguage) : Slugify
{
    $lang = preg_split('/[-_]/',"$targetLanguage-")[0];
    $ruleset = match($lang) {
        'pl' => 'polish',
        'en' => 'english',
        'de' => 'german',
        'cz','cs' => 'czech',
        'sk' => 'slovak',
        'bg' => 'bulgarian',
        'fr' => 'french',
        'es' => 'spanish',
        'it' => 'italian',
        'lt' => 'lithuanian',
        default => 'default'
    };
    return new Slugify(['rulesets' => array_unique(['default',$ruleset])]);
}

function parseItems(array $items) : array
{
    return array_map(
        fn(Element $item) : array => [
            'id' => $item->getAttribute('data-loco-id'),
            'resname' => $item->getAttribute('data-resname'),
            'value' => Util::numeric_entities($item->innerHTML)
        ],
        $items
    );
}

function parseDuplicateSets(array $duplicateSets) : array
{
    $doc = new Document();
    $doc->html($doc->newNodeList($duplicateSets));
    return array_merge(...array_map(
        fn(Element $duplicateSet) : array => array_map(
            function(Element $id) use($duplicateSet) : array {
                return [
                    'id' => $id->getAttribute('data-loco-id'),
                    'resname' => $id->getAttribute('data-resname'),
                    'value' => Util::numeric_entities(FQ($duplicateSet)->find('.value','child::')->first()->html())
                ];
            },
            iterator_to_array(FQ($duplicateSet)->find('ul.ids > li','child::'))
        ),
        $duplicateSets
    ));
}

/**
 * @throws Exception
 */
function cmd_xlf(string $inputString) : string
{
    $dom = new Document(); $dom->html($inputString);
    $body = $dom->find('body');
    $useLongTags = 'yes' === $body->attr('data-long-tags');
    $mergeDuplicates = 'yes' === $body->attr('data-merge-duplicates');
    $original = $body->attr('data-original');
    $sourceLanguage = $body->attr('data-source-language');
    $targetLanguage = $body->attr('data-target-language');

    $shortcodeConverter = makeShortcodeConverter($useLongTags);
    $dom = new Document(); $dom->html($inputString);
    $items = iterator_to_array($dom->find('body > section'));
    $data = $mergeDuplicates ? parseDuplicateSets($items) : parseItems($items);
    $data = array_map(fn($item) => [
        ...$item,
        'value' => htmlspecialchars($shortcodeConverter->html2sc($item['value']))
    ],$data);
    /** @noinspection PhpParamsInspection */
    return renderTemplate(...[...xlfTemplate([
        '%%%original%%%' => $original,
        '%%%source-language%%%' => $sourceLanguage,
        '%%%target-language%%%' => $targetLanguage
    ]), $data]);
}

function hydrate(string $tpl, array $data) : string
{
    return str_replace(
        array_map(fn(string $k) : string => "%%%$k%%%", array_keys($data)),
        array_values($data),
        $tpl
    );
}

function renderTemplate(string $docTpl, string $itemTpl, array $items) : string
{
    $renderedItems = array_map(
        fn(array $item) : string => hydrate($itemTpl, $item),
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
	    data-long-tags="%%%use-long-tags%%%"
	    data-merge-duplicates="no"
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

function renderTemplateWithDeduplication(string $docTpl, string $itemTpl, string $idTpl, array $items) : string
{
    $renderedItems = array_map(
        fn(array $itemWithRenderedIds) : string => hydrate($itemTpl, $itemWithRenderedIds),
        array_map(
            fn(array $item) : array => [
                ...$item,
                'IDS' => implode("            \n", array_map(fn(array $id) : string => hydrate($idTpl, $id), $item['IDS']))
            ],
            $items
        )
    );
    return str_replace('%%%ITEMS%%%',implode("\n",$renderedItems),$docTpl);
}

function htmlTemplateWithDeduplication(array $substitutions=[]) : array
{
        $html = <<<HTML
<!DOCTYPE html>
<html lang="pl">
    <head>
        <title translate="no">String catalog (no duplication)</title>
        <body
            id="the-body"
            data-original="%%%original%%%"
            data-source-language="%%%source-language%%%"
            data-target-language="%%%target-language%%%"
            data-long-tags="%%%use-long-tags%%%"
            data-merge-duplicates="yes"
        >
            %%%ITEMS%%%        
        </body>    
    </head>
</html>
HTML;
    $item=<<<ITEM
    <section class="duplicate-set">
        <ul class="ids" translate="no">
            %%%IDS%%%
        </ul>
        <section class="value">%%%value%%%</section>
    </section>
ITEM;

    $id = <<<ID
    <li data-loco-id="%%%id%%%" data-resname="%%%resname%%%"></li>
ID;

    [$k,$v] = [array_keys($substitutions),array_values($substitutions)];
    return [
        str_replace($k,$v, $html),
        str_replace($k,$v,$item),
        str_replace($k,$v,$id)
    ];
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
