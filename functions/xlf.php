<?php

use QueryPath\DOMQuery;
use QueryPath\DOMQuery as DQ;
use Rulatir\Cdatify\Utility as Util;

function parseItems(DQ $items) : array
{
    $result = [];
    $items->each(function($n, DOMElement $item_) use(&$result) {
        $item = QQ($item_);
        $result[] = [
            'id' => $item->attr('data-loco-id'),
            'resname' => $item->attr('data-resname'),
            'directive' => slugDirectiveOf($item_),
            'transform' => $item_->getAttribute('data-transform'),
            'prefix' => $item_->getAttribute('data-prefix'),
            'value' => Util::numeric_entities($item->innerHTML5())
        ];
    });
    return $result;
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
                 'directive' => slugDirectiveOf($id),
                 'transform' => $id->getAttribute('data-transform'),
                 'prefix' => $id->getAttribute('data-prefix'),
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

    // Create JSON converters for registered patterns
    $jsonConverters = [];
    foreach (JSON_CONVERSION_REGISTRY as [$pattern, $schemeName]) {
        $jsonConverters[] = [
            'pattern' => $pattern,
            'converter' => makeJsonConverter($schemeName)
        ];
    }

    $data = array_map(function($item) use ($shortcodeConverter, $jsonConverters, $targetLanguage) {
        // First convert from shortcode HTML back to shortcodes
        $converted = $shortcodeConverter->html2sc($item['value']);

        // Then check if this needs JSON conversion back
        $resname = $item['resname'];
        foreach ($jsonConverters as ['pattern' => $pattern, 'converter' => $converter]) {
            if (preg_match($pattern, $resname)) {
                $converted = $converter->html2json($converted);
                break;
            }
        }

        // Slugs were sent as text; the item's directive turns the translation back into a slug
        if ('slugify' === $item['transform']) {
            $converted = applySlugDirective($converted, $item['prefix'], $targetLanguage);
        }

        return [
            ...$item,
            'value' => htmlspecialchars($converted)
        ];
    }, $data);

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