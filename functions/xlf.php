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

function parseDuplicateSets(DQ $duplicateSets) : array
{
    $result=[];
    $duplicateSets->each(function($n, DOMElement $ds_) use(&$result) {
        $ds = QQ($ds_);
        $value = $ds->find('> .value')->innerHTML5();
        $ds->find('> ul.ids > li')->each(function($n, DOMElement $id) use (&$result,$value) {
             $result[] = [
                 'id' => $id->getAttribute('data-loco-id'),
                 'resname' => $id->getAttribute('data-resname'),
                 'value' => $value
             ];
        });
    });
    return $result;
}

/**
 * @throws Exception
 */
function cmd_xlf(string $inputString) : string
{
    $dom = html5qp($inputString);
    $body = $dom->find('body');
    $useLongTags = 'yes' === $body->attr('data-long-tags');
    $shortcodeConverter = makeShortcodeConverter($useLongTags);
    $dom = html5qp($inputString);
    $items = $dom->find('body > section');
    [
        'mergeDuplicates' => $mergeDuplicates,
        'original' => $original,
        'sourceLangauge' => $sourceLanguage,
        'targetLanguage' => $targetLanguage,
        'data' => $data
    ] = parseTranslationHTML($inputString);

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

function parseTranslationHTML(string $inputString) : array
{
    $dom = html5qp($inputString);
    $body = $dom->find('body');
    $mergeDuplicates = 'yes' === $body->attr('data-merge-duplicates');
    $original = $body->attr('data-original');
    $sourceLanguage = $body->attr('data-source-language');
    $targetLanguage = $body->attr('data-target-language') ?: withDefaultCountry($dom->find('html')->attr('lang'));
    $useLongTags = 'yes' === $body->attr('use-long-tags');

    $dom = html5qp($inputString);
    $items = $dom->find('body > section');
    return [
        'mergeDuplicates' => $mergeDuplicates,
        'original' => $original,
        'sourceLangauge' => $sourceLanguage,
        'targetLanguage' => $targetLanguage,
        'useLongTags' => $useLongTags,
        'data' => $mergeDuplicates ? parseDuplicateSets($items) : parseItems($items)
    ];
}

function withDefaultCountry(string $language) : string
{
    [$lang, $country]= explode("-","$language--");
    if ($country) return $language;
    return "$lang-".match($lang) {
        'cs' => 'CZ',
        'en' => 'GB',
        default => strtoupper($lang)
    };
}