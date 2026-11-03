<?php

use Cocur\Slugify\Slugify;
use Sarhan\Flatten\Flatten;

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
    $targetLanguage = $file->getAttribute('target-language');
    $slugifier = makeSlugifier($targetLanguage);
    /** @var DOMElement $slugElement */
    foreach($elements['.slug'] as $resKey => $slugElement) {
        $slugSource=$slugElement;
        foreach($slugSources as $slugSourceKey) if ($slugSource = $elements[$slugSourceKey][$resKey]) break;
        $slugElement->textContent=$slugifier->slugify(preprocessApostrophes($slugSource->textContent, $targetLanguage));
    }
    return $dom->saveXML();
}

function makeSlugifier(string $language, ?string $properNamesLanguage=null) : Slugify
{
    $rulesets = [
        ['default'],
        [languageCodeToRuleSet($language)],
        [languageCodeToRuleSet($properNamesLanguage ?? $language)]
    ];

    return new Slugify(['rulesets' => array_unique(array_filter((new Flatten())->flattenToArray($rulesets)))]);
}

/**
 * A slug without its words of one or two letters (`przyjecia-i-wesela` →
 * `przyjecia-wesela`), for the fallback comparison of slugs: editors often drop
 * conjunctions and prepositions when they write a slug by hand. Digits stay.
 */
function strongSlug(string $slug) : string
{
    return implode('-', array_filter(explode('-', $slug), fn($part) => !preg_match('/^\p{L}{1,2}$/u', $part)));
}

/**
 * True when $text slugifies to $slug, or, as a fallback, when both have the
 * same non-empty strongSlug().
 */
function slugifiesTo(string $text, string $slug, Slugify $slugifier, string $language) : bool
{
    $slugified = $slugifier->slugify(preprocessApostrophes($text, $language));
    if ($slugified === $slug) return true;
    $strong = strongSlug($slugified);
    return '' !== $strong && $strong === strongSlug($slug);
}

function preprocessApostrophes(string $text, string $language) : string
{
    // For English, remove word-internal apostrophes
    $languageCode = preg_split('/[-_]/', "$language-")[0];
    if ($languageCode === 'en') {
        $text = preg_replace("/(?<=[a-zA-Z])['\'`'](?=[a-zA-Z])/u", '', $text);
    }
    return $text;
}

function languageCodeToRuleSet(string $languageCode) : string|array {
    return match(preg_split('/[-_]/',"$languageCode-")[0]) {
        'pl' => 'polish',
        'de' => 'german',
        'cz','cs' => 'czech',
        'sk' => 'slovak',
        'bg' => 'bulgarian',
        'fr' => 'french',
        'es' => 'spanish',
        'it' => 'italian',
        'lt' => 'lithuanian',
        'ru' => 'russian',
        'uk' => ['russian','ukrainian'],
        default => 'default'
    };
}

