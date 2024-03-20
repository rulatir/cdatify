<?php

use QueryPath\DOMQuery;
use QueryPath\DOMQuery as DQ;
use Rulatir\Cdatify\Utility as Util;

function parseItems(array $items) : array
{
    return array_map(
        fn(DOMQuery $item) : array => [
            'id' => $item->attr('data-loco-id'),
            'resname' => $item->attr('data-resname'),
            'value' => Util::numeric_entities($item->innerHTML5())
        ],
        $items
    );
}

function parseDuplicateSets(array $duplicateSets) : array
{
    return array_merge(...array_map(
        fn(DQ $duplicateSet) : array => array_map(
            function(DQ $id) use($duplicateSet) : array {
                return [
                    'id' => $id->getAttribute('data-loco-id'),
                    'resname' => $id->getAttribute('data-resname'),
                    'value' => Util::numeric_entities(QQ($duplicateSet)->find('> .value')->first()->innerHTML5())
                ];
            },
            iterator_to_array($duplicateSet->find('> ul.ids > li'))
        ),
        $duplicateSets
    ));
}

/**
 * @throws Exception
 */
function cmd_xlf(string $inputString) : string
{
    $dom = html5qp($inputString);
    $body = $dom->find('body');
    $useLongTags = 'yes' === $body->attr('data-long-tags');
    $mergeDuplicates = 'yes' === $body->attr('data-merge-duplicates');
    $original = $body->attr('data-original');
    $sourceLanguage = $body->attr('data-source-language');
    $targetLanguage = $body->attr('data-target-language');

    $shortcodeConverter = makeShortcodeConverter($useLongTags);
    $dom = html5qp($inputString);
    $items = iterator_to_array(pieces($dom->find('body > section')));
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

