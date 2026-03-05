<?php

use Matecat\XliffParser\XliffParser;

function cmd_html(
    string $inputString,
    bool $useLongTags,
    bool $mergeDuplicates,
    ?string $codeOutputPath = null,
    array $noNlHeuristics = [],
    array $exclusionRegexes = []
) : string
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

    // Convert all units and check for natural language content
    $units = array_map(
        function ($unit) use ($shortcodeConverter, $noNlHeuristics, $exclusionRegexes) {
            $originalSource = htmlspecialchars_decode($unit['source']['raw-content']);
            $convertedHtml = $shortcodeConverter->sc2html($originalSource);
            echo "";
            return [
                'id' => $unit['attr']['id'],
                'resname' => $unit['attr']['resname'],
                'originalSource' => $originalSource,
                'value' => $convertedHtml,
                'hasNL' => containsNaturalLanguage(
                    $originalSource,
                    $convertedHtml,
                    $noNlHeuristics,
                    $exclusionRegexes
                )
            ];
        },
        array_merge(...array_map(fn($f) => array_values($f['trans-units']),$files))
    );

    // Split units by NL presence
    $nlUnits = array_values(array_filter($units, fn($u) => $u['hasNL']));
    $codeUnits = array_values(array_filter($units, fn($u) => !$u['hasNL']));

    // Output code XLF if requested
    if ($codeOutputPath !== null) {
        $codeData = array_map(fn($u) => [
            'id' => $u['id'],
            'resname' => $u['resname'],
            'source' => htmlspecialchars($u['originalSource'])
        ], $codeUnits);

        /** @noinspection PhpParamsInspection */
        $codeXlf = renderTemplate(...[
            ...sourceXlfTemplate([
                '%%%original%%%' => $original,
                '%%%source-language%%%' => $sourceLanguage,
                '%%%target-language%%%' => $targetLanguage
            ]),
            $codeData
        ]);

        @mkdir(dirname($codeOutputPath), 0755, true);
        file_put_contents($codeOutputPath, $codeXlf);
    }

    // Prepare NL units for HTML output
    $nlUnitsForHtml = array_map(fn($u) => [
        'id' => $u['id'],
        'resname' => $u['resname'],
        'value' => $u['value']
    ], $nlUnits);

    $templateParams = [
        '%%%original%%%' => $original,
        '%%%source-language%%%' => $sourceLanguage,
        '%%%target-language%%%' => $targetLanguage,
        '%%%use-long-tags%%%' => $useLongTags ? "yes" : "no"
    ];

    /** @noinspection PhpParamsInspection */
    return
        $mergeDuplicates
            ? renderTemplateWithDeduplication(...[...htmlTemplateWithDeduplication($templateParams), mergeDuplicates($nlUnitsForHtml)])
            : renderTemplate(...[...htmlTemplate($templateParams), $nlUnitsForHtml]);
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

function normalizeTextContentForTranslation(string $text) : string
{
    $text = normalizePipes($text);
    /** @noinspection PhpUnnecessaryLocalVariableInspection */
    $text = normalizeStars($text,'%');
    return $text;
}

function normalizePipes(string $text) : string
{
    return implode(" ",preg_split(/** @lang regex */ '/\s*\|+\s*/', $text));
}

function normalizeStars(string $text, string $template = '<md-star>%</md-star>') : string
{
    return preg_replace_callback(
        '/(?:\\w|[*])+/u',
        function(array $matches) use ($template): string{
            if(!str_contains($matches[0],'*')) return $matches[0];
            if(str_contains($matches[0],'**')) return $matches[0];
            if(substr_count($matches[0],'*') % 2 === 0) {
                return str_replace(
                    '%',
                    str_replace('*','',$matches[0]),
                    $template
                );
            }
            return $matches[0];
        },
        $text
    );
}

/**
 * Determines if a string contains natural language after HTML conversion.
 *
 * A string is considered to contain natural language if the converted HTML
 * contains any text nodes that are both non-blank and translatable (no
 * translate="no" on the node or its ancestors).
 *
 * @param string $originalSource The original source string
 * @param string $convertedHtml The HTML after AVAT and shortcode conversion
 * @param array $noNlHeuristics Additional heuristics (callables) that can flag strings as no-NL
 * @param array $exclusionRegexes Regex patterns that flag text as no-NL if matched
 * @return bool True if natural language is present, false otherwise
 */
function containsNaturalLanguage(
    string $originalSource,
    string $convertedHtml,
    array $noNlHeuristics = [],
    array $exclusionRegexes = []
): bool
{
    // Check additional heuristics first (they might be faster)
    foreach ($noNlHeuristics as $heuristic) {
        if ($heuristic($originalSource, $convertedHtml)) {
            return false; // Heuristic says no NL
        }
    }

    // Main heuristic: check for translatable text nodes
    return hasTranslatableTextNodes($convertedHtml, $exclusionRegexes);
}

/**
 * Checks if HTML contains any non-blank text nodes that are translatable.
 *
 * @param string $html The HTML to check
 * @param array $exclusionRegexes Regex patterns for excluding text from NL
 * @return bool True if translatable text nodes exist, false otherwise
 */
function hasTranslatableTextNodes(string $html, array $exclusionRegexes = []): bool
{
    if (trim($html) === '') {
        return false; // Empty HTML has no translatable content
    }

    $container = Rulatir\Cdatify\Utility::documentify($html);
    $domNode = $container->get(0); // Get underlying DOMElement

    if (!$domNode) {
        return false;
    }

    return hasTranslatableTextNodesInSubtree($domNode, $exclusionRegexes);
}

/**
 * Recursively checks a DOM subtree for non-blank translatable text nodes.
 *
 * @param DOMNode $node The node to check
 * @param array $exclusionRegexes Regex patterns for excluding text from NL
 * @return bool True if a translatable text node is found
 */
function hasTranslatableTextNodesInSubtree(DOMNode $node, array $exclusionRegexes = []): bool
{
    if ($node instanceof DOMText) {
        $trimmed = trim($node->textContent);
        if ($trimmed !== ''
            && isNodeTranslatable($node)
            && isTextContentNaturalLanguage($trimmed, $exclusionRegexes)) {
            return true;
        }
    }

    if ($node->hasChildNodes()) {
        foreach ($node->childNodes as $child) {
            if (hasTranslatableTextNodesInSubtree($child, $exclusionRegexes)) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Checks if a DOM node is translatable (no translate="no" on ancestors).
 *
 * @param DOMNode $node The node to check
 * @return bool True if translatable, false otherwise
 */
function isNodeTranslatable(DOMNode $node): bool
{
    $current = $node->parentNode;
    while ($current && $current instanceof DOMElement) {
        if ($current->getAttribute('translate') === 'no') {
            return false;
        }
        $current = $current->parentNode;
    }
    return true;
}

/**
 * Determines if text content contains natural language.
 *
 * Text is considered NOT natural language (returns false) if it:
 * - Contains no letters (using Unicode letter definition)
 * - Is a valid tel:, mailto:, or HTTP(S) URL
 * - Looks like a strong password/token (high entropy, no whitespace)
 * - Matches any of the provided exclusion regexes
 *
 * @param string $text The trimmed text content to check
 * @param array $exclusionRegexes Additional regex patterns that flag text as no-NL
 * @return bool True if text is natural language, false otherwise
 */
function isTextContentNaturalLanguage(string $text, array $exclusionRegexes = []): bool
{
    // Check if text contains no letters (using Unicode letter class)
    if (!preg_match('/\p{L}/u', $text)) {
        return false; // No letters = not natural language
    }

    // Check if text is a URL
    if (isUrl($text)) {
        return false; // URLs are not natural language
    }

    // Check if text looks like a strong password/ID/token
    if (looksLikeStrongPassword($text)) {
        return false; // High-entropy tokens are not natural language
    }

    // Check custom exclusion regexes
    foreach ($exclusionRegexes as $pattern) {
        if (@preg_match($pattern, $text)) {
            return false; // Matches exclusion pattern
        }
    }

    return true; // Passed all checks, is natural language
}

/**
 * Checks if text is a valid tel:, mailto:, or HTTP(S) URL.
 *
 * @param string $text The text to check
 * @return bool True if text is a URL, false otherwise
 */
function isUrl(string $text): bool
{
    // Check for tel: URL
    if (preg_match('/^tel:[+\d\s\-().]+$/i', $text)) {
        return true;
    }

    // Check for mailto: URL (basic validation)
    if (preg_match('/^mailto:[^\s@]+@[^\s@]+\.[^\s@]+$/i', $text)) {
        return true;
    }

    // Check for HTTP(S) URL using filter_var
    if (filter_var($text, FILTER_VALIDATE_URL) !== false) {
        $scheme = parse_url($text, PHP_URL_SCHEME);
        if (in_array(strtolower($scheme ?? ''), ['http', 'https'])) {
            return true;
        }
    }

    return false;
}

/**
 * Checks if text looks like a strong password or random token.
 *
 * This helps identify things like YouTube video IDs, API keys, tokens, etc.
 * that should not be translated but are hard to distinguish from natural language.
 *
 * Criteria:
 * - Contains no internal whitespace (must be a single token)
 * - Has high password strength according to zxcvbn (score >= 3)
 *
 * @param string $text The text to check
 * @return bool True if text looks like a strong password/token, false otherwise
 */
function looksLikeStrongPassword(string $text): bool
{
    // If text contains whitespace, it's not a single token
    if (preg_match('/\s/', $text)) {
        return false;
    }

    // Use zxcvbn to estimate password strength
    $zxcvbn = new ZxcvbnPhp\Zxcvbn();
    $strength = $zxcvbn->passwordStrength($text);

    // Score 3 or 4 indicates strong password
    // Score 0-2 is weak/medium (more likely to be natural language)
    return $strength['score'] >= 3;
}

