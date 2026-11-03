<?php

use Cocur\Slugify\Slugify;

const SLUG_RESNAME_SUFFIX = '.slug';
const SLUG_TITLE_RESNAME_SUFFIX = '.title';

function isSlugResname(string $resname) : bool
{
    return str_ends_with($resname, SLUG_RESNAME_SUFFIX);
}

function ownTitleResname(string $slugResname) : string
{
    return substr($slugResname, 0, -strlen(SLUG_RESNAME_SUFFIX)) . SLUG_TITLE_RESNAME_SUFFIX;
}

/**
 * Indexes the source texts of non-slug units for turning slugs back into text.
 * 'phrases': slugified text => [text => true], for texts without markup or shortcodes.
 * 'strongPhrases': the same by strongSlug(), for the fallback.
 * 'words': slugified word => [lowercase word => true], for every word of every text.
 *
 * @param array<string,string> $textsByResname
 */
function slugTextIndex(array $textsByResname, Slugify $slugifier, string $language) : array
{
    $phrases = [];
    $strongPhrases = [];
    $words = [];
    foreach ($textsByResname as $resname => $text) {
        if (isSlugResname($resname)) continue;
        $text = trim($text);
        if ('' === $text) continue;
        if (!preg_match('/[<\[]/', $text)) {
            $slugified = $slugifier->slugify(preprocessApostrophes($text, $language));
            $phrases[$slugified][$text] = true;
            if ('' !== ($strong = strongSlug($slugified))) $strongPhrases[$strong][$text] = true;
        }
        $plain = strip_tags(preg_replace('/\[[^\[\]]*\]/u', ' ', $text));
        preg_match_all('/\p{L}+/u', $plain, $matches);
        foreach ($matches[0] as $word) {
            $word = mb_strtolower($word);
            $words[$slugifier->slugify($word)][$word] = true;
        }
    }
    return ['phrases' => $phrases, 'strongPhrases' => $strongPhrases, 'words' => $words];
}

/**
 * Turns a slug back into source-language text, in this order:
 * 1. the unit's own title, if it slugifies to the slug;
 * 2. the text of other units that slugify to the slug, if they all have the same text;
 * 3. word by word, using the one word form the index has for each part of the slug.
 * A part with no form or with several forms stays as it is and is reported in 'unresolved'.
 * A leading '#' is returned in 'prefix', as in fix-slugs' strict rule.
 *
 * @return array{prefix: string, text: string, unresolved: array<string,string[]>}
 */
function deslugify(string $slug, ?string $ownTitle, array $index, Slugify $slugifier, string $language) : array
{
    preg_match('/^(#?)(.*)$/s', $slug, $m);
    [, $prefix, $core] = $m;

    $ownTitle = trim($ownTitle ?? '');
    if ('' !== $ownTitle && slugifiesTo($ownTitle, $core, $slugifier, $language)) {
        return ['prefix' => $prefix, 'text' => $ownTitle, 'unresolved' => []];
    }

    $phraseHits = $index['phrases'][$core]
        ?? ('' === strongSlug($core) ? [] : ($index['strongPhrases'][strongSlug($core)] ?? []));
    if (1 === count($phraseHits)) {
        return ['prefix' => $prefix, 'text' => array_key_first($phraseHits), 'unresolved' => []];
    }

    $words = [];
    $unresolved = [];
    foreach (explode('-', $core) as $part) {
        $forms = array_keys($index['words'][$part] ?? []);
        if (1 === count($forms)) {
            $words[] = $forms[0];
            continue;
        }
        $words[] = $part;
        if (preg_match('/\p{L}/u', $part)) $unresolved[$part] = $forms;
    }
    return ['prefix' => $prefix, 'text' => implode(' ', $words), 'unresolved' => $unresolved];
}

/**
 * The attributes that tell `xlf` how to turn a translated slug text back into a slug.
 */
function slugDirectiveAttributes(string $prefix) : string
{
    return ' data-transform="slugify"' . ('' === $prefix ? '' : ' data-prefix="' . htmlspecialchars($prefix) . '"');
}

/**
 * Executes the slugify directive on a translated text: slugifies it with the target language's
 * rules and puts the recorded prefix in front.
 */
function applySlugDirective(string $translatedText, string $prefix, string $language) : string
{
    static $slugifiers = [];
    $slugifiers[$language] ??= makeSlugifier($language);
    $plain = html_entity_decode(strip_tags($translatedText), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return $prefix . $slugifiers[$language]->slugify(preprocessApostrophes($plain, $language));
}

/**
 * The directive attributes of a parsed item or id element, rendered again, so that commands
 * which read a translation HTML document and write a new one keep them.
 */
function slugDirectiveOf(DOMElement $element) : string
{
    return 'slugify' === $element->getAttribute('data-transform')
        ? slugDirectiveAttributes($element->getAttribute('data-prefix'))
        : '';
}
