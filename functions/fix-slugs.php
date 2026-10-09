<?php

use Cocur\Slugify\Slugify;
use JetBrains\PhpStorm\NoReturn;

/**
 * Parse a rule specification like /pattern/template/
 * Handles escaped delimiters correctly with multibyte support.
 *
 * @param string $ruleSpec Rule like /(?P<match>.*)(-all-\d+)$/$T$2/
 * @return array [pattern (regex with delimiter), template (replacement)]
 */
function parseRule(string $ruleSpec) : array
{
    $length = mb_strlen($ruleSpec, 'UTF-8');
    if ($length === 0) {
        throw new InvalidArgumentException("Empty pattern");
    }

    $delimiter = mb_substr($ruleSpec, 0, 1, 'UTF-8');
    $i = 1;
    $escaped = false;
    $parts = [];
    $current = '';

    // Parse two parts: regex and replacement
    for ($partNum = 0; $partNum < 2 && $i < $length; $partNum++) {
        $current = '';
        while ($i < $length) {
            $char = mb_substr($ruleSpec, $i, 1, 'UTF-8');

            if ($escaped) {
                $current .= $char;
                $escaped = false;
            } elseif ($char === '\\') {
                $current .= $char;
                $escaped = true;
            } elseif ($char === $delimiter) {
                // Found unescaped delimiter - end of this part
                $parts[] = $current;
                $i++;
                break;
            } else {
                $current .= $char;
            }

            $i++;
        }
    }

    if (count($parts) < 2) {
        throw new InvalidArgumentException("Pattern must be in format {delimiter}regex{delimiter}replacement{delimiter}");
    }

    $regex = $delimiter . $parts[0] . $delimiter;
    $replacement = $parts[1];

    return [$regex, $replacement];
}

/**
 * Helper: Check if value matches a slug rule
 * Returns [core, matches] if it's a slug by this rule, null otherwise
 */
function matchSlugRule(string $value, string $pattern, Slugify $slugifier, string $language) : ?array
{
    if (!preg_match($pattern, $value, $matches)) {
        return null;
    }

    if (!isset($matches['match'])) {
        return null;
    }

    $core = $matches['match'];
    $coreSlugified = $slugifier->slugify(preprocessApostrophes($core, $language));

    // For it to be a slug, core must slugify to itself
    if ($coreSlugified !== $core) {
        return null;
    }

    return [$core, $matches];
}

/**
 * Render a slug template with captured groups and target core
 */
function renderSlugTemplate(string $targetCore, array $captures, string $template) : string
{
    $replacements = ['$T' => $targetCore];
    foreach ($captures as $i => $capture) {
        if (is_int($i)) $replacements['$' . $i] = $capture;
    }
    uksort($replacements, fn($a, $b) => strlen($b) - strlen($a));

    $result = $template;
    foreach ($replacements as $placeholder => $replValue) {
        $result = str_replace($placeholder, $replValue, $result);
    }
    return $result;
}

#[NoReturn]
function cmd_fix_slugs(array $target, string $targetLang, array $source, string $sourceLang, array $looseMatchPatterns = [], ?string $problemsFile = null) : string
{
    $targetSlugifier = makeSlugifier($targetLang);
    $sourceSlugifier = makeSlugifier($sourceLang);

    // Hardcoded whitelist of slug key suffixes
    $slugSuffixes = ['.slug'];

    // Prepend strict rule with hash handling
    $allRuleSpecs = array_merge(['/^(#?)(?P<match>.*)$/$1$T/'], $looseMatchPatterns);

    // Parse all rules into [pattern, template] pairs
    $rules = [];
    foreach ($allRuleSpecs as $ruleSpec) {
        [$pattern, $template] = parseRule($ruleSpec);
        $rules[] = [$pattern, $template];
    }

    // First loop: classify source entries as slugs or non-slugs
    $slugs = []; // [[key, core, matches, template], ...]
    $titleKeysBySlugCore = []; // core => [key1, key2, ...] - keys of titles that produce this slug core

    foreach ($source as $key => $value) {
        // Check if key ends with a slug suffix
        $isSlugKey = false;
        foreach ($slugSuffixes as $suffix) {
            if (str_ends_with($key, $suffix)) {
                $isSlugKey = true;
                break;
            }
        }

        if (!$isSlugKey) {
            // Not a slug key - treat as slug source (title) without checking
            $slugification = $sourceSlugifier->slugify(preprocessApostrophes($value, $sourceLang));
            $titleKeysBySlugCore[$slugification] ??= [];
            $titleKeysBySlugCore[$slugification][] = $key;
        } else {
            // Is a slug key - check if value matches strict rule
            $strictMatch = matchSlugRule($value, $rules[0][0], $sourceSlugifier, $sourceLang);

            if ($strictMatch !== null) {
                // It's a valid slug
                [$core, $matches] = $strictMatch;
                $slugs[] = [$key, $core, $matches, $rules[0][1]];
            } else {
                // Slug key but value doesn't match strict rule - still treat as slug (for loose matching)
                $slugs[] = [$key, null, null, $rules[0][1]];
            }
        }
    }

    // Second loop: process slugs
    $processedSlugs = []; // Track which slugs were successfully processed
    $problems = []; // Track slugs that weren't replaced with reasons

    foreach ($slugs as [$slugKey, $slugCore, $slugMatches, $slugTemplate]) {
        $replaced = false;

        foreach ($rules as $i => [$pattern, $template]) {
            // For i=0 (strict rule), already computed in first loop
            if ($i > 0) {
                $match = matchSlugRule($source[$slugKey], $pattern, $sourceSlugifier, $sourceLang);
                if ($match === null) continue;
                [$slugCore, $slugMatches] = $match;
                $slugTemplate = $template;
            }

            if ($slugCore === null) continue; // No valid core from this rule

            // The same item's title comes first: if it slugifies to the core and has a translation, use it
            $ownTitleKey = substr($slugKey, 0, -strlen('.slug')) . '.title';
            $ownTitleTarget = trim($target[$ownTitleKey] ?? '');
            if ($ownTitleTarget !== '' && isset($source[$ownTitleKey])
                && $sourceSlugifier->slugify(preprocessApostrophes($source[$ownTitleKey], $sourceLang)) === $slugCore) {
                $target[$slugKey] = renderSlugTemplate(
                    $targetSlugifier->slugify(preprocessApostrophes($ownTitleTarget, $targetLang)),
                    $slugMatches,
                    $slugTemplate
                );
                $processedSlugs[$slugKey] = true;
                $replaced = true;
                break;
            }

            $titleKeys = $titleKeysBySlugCore[$slugCore] ?? [];
            if (count($titleKeys) === 0) {
                if (!$replaced && $problemsFile !== null) {
                    $problems[$slugKey] = ['reason' => 'no_matching_titles', 'core' => $slugCore];
                }
                continue;
            }

            // Slugify the non-empty target translations; they must all agree
            $targetCores = [];
            foreach ($titleKeys as $titleKey) {
                $targetValue = trim($target[$titleKey] ?? '');
                if ($targetValue === '') continue;
                $targetCores[$targetSlugifier->slugify(preprocessApostrophes($targetValue, $targetLang))] = true;
            }

            if (count($targetCores) === 1) {
                $target[$slugKey] = renderSlugTemplate(array_key_first($targetCores), $slugMatches, $slugTemplate);
                $processedSlugs[$slugKey] = true;
                $replaced = true;
                break; // Found a matching rule, stop trying other rules
            }

            $mismatchReason = count($targetCores) === 0 ? 'missing_translation' : 'ambiguous_translations';
            if ($problemsFile !== null) {
                $problems[$slugKey] = ['reason' => $mismatchReason, 'core' => $slugCore];
            }
            break; // Skip this slug entirely
        }
    }

    // Generate problem report if requested
    if ($problemsFile !== null && count($problems) > 0) {
        generateProblemReport($problemsFile, $problems, $source, $target, $slugSuffixes);
    }

    $result = var_export($target, true);
    return "<?php\nreturn $result;\n";
}

/**
 * Generate problem report for slugs that couldn't be replaced
 */
function generateProblemReport(string $filename, array $problems, array $source, array $target, array $slugSuffixes) : void
{
    $report = [];

    foreach ($problems as $slugKey => $problemInfo) {
        // Strip slug suffix to get prefix
        $prefix = $slugKey;
        foreach ($slugSuffixes as $suffix) {
            if (str_ends_with($slugKey, $suffix)) {
                $prefix = substr($slugKey, 0, -strlen($suffix));
                break;
            }
        }

        // Find all related entries (prefix + dot + single lowercase word)
        $relatedPattern = '/^' . preg_quote($prefix, '/') . '\.([a-z]+)$/';
        $sourceRelated = [];
        $targetRelated = [];

        foreach ($source as $key => $value) {
            if (preg_match($relatedPattern, $key)) {
                $sourceRelated[$key] = $value;
            }
        }

        foreach ($target as $key => $value) {
            if (preg_match($relatedPattern, $key)) {
                $targetRelated[$key] = $value;
            }
        }

        $report[] = [
            'slug_key' => $slugKey,
            'reason' => $problemInfo['reason'],
            'core' => $problemInfo['core'] ?? null,
            'source_related' => $sourceRelated,
            'target_related' => $targetRelated
        ];
    }

    file_put_contents($filename, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function dummyfunc() {}