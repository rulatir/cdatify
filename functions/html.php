<?php

use Matecat\XliffParser\XliffParser;

// Digrams considered "letter-letter-like" for natural language detection
const LETTER_LIKE_DIGRAMS = ['aa', 'a-', '-a', 'a_', '_a', 'a.', '.a', '.-', './'];

// Threshold for token detection: ratio < threshold → token, ratio >= threshold → NL
const TOKEN_DIGRAM_RATIO_THRESHOLD = 3.5;

function cmd_html(
    string $inputString,
    bool $useLongTags,
    bool $mergeDuplicates,
    ?string $codeOutputPath = null,
    array $noNlHeuristics = [],
    array $inclusionRegexes = [],
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

    // Create JSON converters for registered patterns
    $jsonConverters = [];
    foreach (JSON_CONVERSION_REGISTRY as [$pattern, $schemeName]) {
        $jsonConverters[] = [
            'pattern' => $pattern,
            'converter' => makeJsonConverter($schemeName)
        ];
    }

    // Convert all units and check for natural language content
    $units = array_map(
        function ($unit) use ($shortcodeConverter, $jsonConverters, $noNlHeuristics, $inclusionRegexes, $exclusionRegexes) {
            $originalSource = htmlspecialchars_decode($unit['source']['raw-content']);
            $resname = $unit['attr']['resname'];

            // Check if this unit needs JSON conversion
            $textToConvert = $originalSource;
            foreach ($jsonConverters as ['pattern' => $pattern, 'converter' => $converter]) {
                if (preg_match($pattern, $resname)) {
                    $textToConvert = $converter->json2html($textToConvert);
                    break;
                }
            }

            // Then apply shortcode conversion
            $convertedHtml = $shortcodeConverter->sc2html($textToConvert);
            echo "";
            return [
                'id' => $unit['attr']['id'],
                'resname' => $resname,
                'originalSource' => $originalSource,
                'value' => $convertedHtml,
                'hasNL' => containsNaturalLanguage(
                    $originalSource,
                    $convertedHtml,
                    $noNlHeuristics,
                    $inclusionRegexes,
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
 * @param array $inclusionRegexes Regex patterns that flag text as NL if matched (checked first)
 * @param array $exclusionRegexes Regex patterns that flag text as no-NL if matched
 * @return bool True if natural language is present, false otherwise
 */
function containsNaturalLanguage(
    string $originalSource,
    string $convertedHtml,
    array $noNlHeuristics = [],
    array $inclusionRegexes = [],
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
    return hasTranslatableTextNodes($convertedHtml, $inclusionRegexes, $exclusionRegexes);
}

/**
 * Checks if HTML contains any non-blank text nodes that are translatable.
 *
 * @param string $html The HTML to check
 * @param array $inclusionRegexes Regex patterns for including text as NL (checked first)
 * @param array $exclusionRegexes Regex patterns for excluding text from NL
 * @return bool True if translatable text nodes exist, false otherwise
 */
function hasTranslatableTextNodes(string $html, array $inclusionRegexes = [], array $exclusionRegexes = []): bool
{
    if (trim($html) === '') {
        return false; // Empty HTML has no translatable content
    }

    $container = Rulatir\Cdatify\Utility::documentify($html);
    $domNode = $container->get(0); // Get underlying DOMElement

    if (!$domNode) {
        return false;
    }

    return hasTranslatableTextNodesInSubtree($domNode, $inclusionRegexes, $exclusionRegexes);
}

/**
 * Recursively checks a DOM subtree for non-blank translatable text nodes.
 *
 * @param DOMNode $node The node to check
 * @param array $inclusionRegexes Regex patterns for including text as NL (checked first)
 * @param array $exclusionRegexes Regex patterns for excluding text from NL
 * @return bool True if a translatable text node is found
 */
function hasTranslatableTextNodesInSubtree(DOMNode $node, array $inclusionRegexes = [], array $exclusionRegexes = []): bool
{
    if ($node instanceof DOMText) {
        $untrimmed = $node->textContent;
        $trimmed = trim($untrimmed);

        // Only process non-empty text nodes
        if ($trimmed !== '' && isNodeTranslatable($node)) {
            // Check untrimmed content for whitespace first
            // If it has whitespace, it's multi-word content and is NL
            if (preg_match('/\s/', $untrimmed)) {
                return true;
            }

            // Otherwise check the trimmed content with other heuristics
            if (isTextContentNaturalLanguage($trimmed, $inclusionRegexes, $exclusionRegexes)) {
                return true;
            }
        }
    }

    if ($node->hasChildNodes()) {
        foreach ($node->childNodes as $child) {
            if (hasTranslatableTextNodesInSubtree($child, $inclusionRegexes, $exclusionRegexes)) {
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
 * Note: This function receives trimmed text and should only be called for single tokens
 * (whitespace check happens earlier at the text node level on untrimmed content).
 *
 * Text is considered natural language (returns true) if it:
 * - Matches any of the provided inclusion regexes (checked first, short-circuits other checks)
 *
 * Text is considered NOT natural language (returns false) if it:
 * - Contains no letters (using Unicode letter definition)
 * - Is a valid tel:, mailto:, or HTTP(S) URL
 * - Looks like a strong password/token (digram analysis)
 * - Matches any of the provided exclusion regexes
 *
 * @param string $text The trimmed text content to check
 * @param array $inclusionRegexes Regex patterns that flag text as NL (checked first, overrides other checks)
 * @param array $exclusionRegexes Additional regex patterns that flag text as no-NL
 * @return bool True if text is natural language, false otherwise
 */
function isTextContentNaturalLanguage(string $text, array $inclusionRegexes = [], array $exclusionRegexes = []): bool
{
    // Check inclusion regexes first - if matched, immediately return true
    foreach ($inclusionRegexes as $pattern) {
        if (@preg_match($pattern, $text)) {
            return true; // Matches inclusion pattern, is NL regardless of other checks
        }
    }

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
 * Normalizes a string by replacing each character with a sigil of its character class.
 *
 * Characters are mapped as follows:
 * - Letters (any script): 'a'
 * - Digits: '0'
 * - Hyphen: '-'
 * - Underscore: '_'
 * - Period: '.'
 * - Slash: '/'
 * - Colon/semicolon: ':'
 * - Comma: ','
 * - Other symbols: '?'
 *
 * @param string $text The text to normalize
 * @return string The normalized string with character class sigils
 */
function normalizeToCharacterClasses(string $text): string
{
    $text = mb_strtolower($text);
    $result = '';

    foreach (mb_str_split($text) as $char) {
        if (preg_match('/\p{L}/u', $char)) {
            $result .= 'a'; // letter
        } elseif (ctype_digit($char)) {
            $result .= '0'; // digit
        } elseif ($char === '-') {
            $result .= '-'; // hyphen
        } elseif ($char === '_') {
            $result .= '_'; // underscore
        } elseif ($char === '.') {
            $result .= '.'; // period
        } elseif ($char === '/') {
            $result .= '/'; // slash
        } elseif (in_array($char, [':', ';'], true)) {
            $result .= ':'; // colon/semicolon
        } elseif ($char === ',') {
            $result .= ','; // comma
        } else {
            $result .= '?'; // other symbol
        }
    }

    return $result;
}

/**
 * Calculates the ratio of letter-letter digrams to most frequent non-letter-letter digram.
 *
 * This metric distinguishes natural language (high ratio of consecutive letters)
 * from random tokens (frequent mixing of letters with digits/symbols).
 *
 * Digrams counted as "letter-letter-like":
 * - 'aa': letter-letter
 * - 'a-', '-a': letter with hyphen
 * - 'a_', '_a': letter with underscore
 * - 'a.', '.a': letter with period
 * - '.-', './': period with hyphen/slash
 *
 * @param string $text The text to analyze
 * @return float|null The ratio, or null if text is too short (< 3 chars)
 */
function calculateLetterLetterDigramRatio(string $text): ?float
{
    $normalized = normalizeToCharacterClasses($text);

    if (strlen($normalized) < 3) {
        return null; // Need at least 3 chars for 2 digrams
    }

    // Count all digrams
    $digrams = [];
    for ($i = 0; $i < strlen($normalized) - 1; $i++) {
        $digram = substr($normalized, $i, 2);
        $digrams[$digram] = ($digrams[$digram] ?? 0) + 1;
    }

    // Separate letter-letter-like from others
    $letterLetterCount = 0;
    $otherDigrams = [];

    foreach ($digrams as $digram => $count) {
        if (in_array($digram, LETTER_LIKE_DIGRAMS, true)) {
            $letterLetterCount += $count;
        } else {
            $otherDigrams[$digram] = $count;
        }
    }

    // Find most frequent non-letter-letter digram
    $maxOtherCount = empty($otherDigrams) ? 0 : max($otherDigrams);

    // If no other digrams, all are letter-letter (high ratio)
    if ($maxOtherCount === 0) {
        return $letterLetterCount > 0 ? INF : 0;
    }

    return $letterLetterCount / $maxOtherCount;
}

/**
 * Checks if text looks like a strong password or random token.
 *
 * This helps identify things like YouTube video IDs, API keys, tokens, etc.
 * that should not be translated but are hard to distinguish from natural language.
 *
 * Uses digram analysis to detect tokens: natural language has high ratios of
 * consecutive letters, while tokens frequently mix letters with digits/symbols.
 *
 * @param string $text The text to check
 * @return bool True if text looks like a strong password/token, false otherwise
 */
function looksLikeStrongPassword(string $text): bool
{
    // Note: This function is only called for strings without whitespace
    // (whitespace check happens earlier in isTextContentNaturalLanguage)

    // Preprocess: strip # from beginning and digits from end
    $preprocessed = $text;
    $preprocessed = ltrim($preprocessed, '#');
    $preprocessed = rtrim($preprocessed, '0123456789');

    // If preprocessing left us with nothing, use original text
    if ($preprocessed === '') {
        $preprocessed = $text;
    }

    $ratio = calculateLetterLetterDigramRatio($preprocessed);

    // If text is too short to analyze, assume it's natural language
    if ($ratio === null) {
        return false;
    }

    // Threshold determined empirically:
    // - NL: pure words (INF), long slugs (5-8), short mixed (4+)
    // - Tokens: YouTube IDs (~3), MD5 hashes (~0.13)
    return $ratio < TOKEN_DIGRAM_RATIO_THRESHOLD;
}

