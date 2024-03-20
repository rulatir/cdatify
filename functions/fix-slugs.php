<?php

use Cocur\Slugify\Slugify;
use JetBrains\PhpStorm\NoReturn;

#[NoReturn]
function cmd_fix_slugs(array $input, string $inputLang, array $source, string $sourceLang) : string
{
    $inputSlugifier = makeSlugifier($inputLang,$sourceLang);
    $sourceSlugifier = makeSlugifier($sourceLang);

    $slugifiedSource = [];
    $sourceBySlugifiedValue = [];
    foreach($source as $key => $value) {
        [$slug, $hadHash] = $slugifiedSource[$key] = hash_slugify($value, $sourceSlugifier);
        if ($slug !== preg_replace('/^#/','',$value)) {
            $sourceBySlugifiedValue[$slug] ??= [];
            $sourceBySlugifiedValue[$slug][$value] ??= [];
            $sourceBySlugifiedValue[$slug][$value][] = $key;
        }
    }
    foreach($input as $key=>$value) {
        $sourceValue = $source[$key];
        [$slugifiedSourceValue, $hadHash] = $slugifiedSource[$key];
        if (1!==count($preimage = $sourceBySlugifiedValue[preg_replace('/^#/','',$sourceValue)] ?? [])) {
            continue;
        }
        $preimageValue = array_key_first($preimage);
        $translations = array_unique(array_values(array_intersect_key($input, array_fill_keys($preimage[$preimageValue],true))));
        if (1===count($translations)) {
            $input[$key] = ($hadHash ? '#' : '') . $inputSlugifier->slugify($translations[0]);
        }
    }
    $result = var_export($input, true);
    return $result;
}

function hash_slugify(string $v, Slugify $slugify) : array
{
    $dehashed = preg_replace('/^#/','',$v);
    return [$slugify->slugify($dehashed),$dehashed!==$v];
}

function dummyfunc() {}