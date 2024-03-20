<?php

use Cocur\Slugify\Slugify;

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

function makeSlugifier(string $language, ?string $properNamesLanguage=null) : Slugify
{
    return new Slugify(['rulesets' => array_unique([
        'default',
        languageCodeToRuleSet($language),
        languageCodeToRuleSet($properNamesLanguage ?? $language)
    ])]);
}

function languageCodeToRuleSet(string $languageCode) : string {
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
        default => 'default'
    };
}

