<?php

use Symfony\Component\Yaml\Yaml;

/**
 * Reads the vocabulary catalogs, one `<locale>.yaml` per locale: asset ID =>
 * {text, wrong: [...]}, `wrong` optional.
 *
 * @return array<string, array<string, array{text: string, wrong: string[]}>> locale => ID => entry
 */
function loadTermCatalogs(string $dir) : array
{
    $catalogs = [];
    foreach (glob(rtrim($dir, '/') . '/*.yaml') as $file) {
        $locale = basename($file, '.yaml');
        foreach (Yaml::parseFile($file) ?? [] as $id => $entry) {
            if (!is_array($entry) || !is_string($entry['text'] ?? null) || '' === trim($entry['text'])) {
                throw new RuntimeException("$locale.yaml: $id: not an entry with a non-empty string `text`");
            }
            $catalogs[$locale][(string)$id] = ['text' => $entry['text'], 'wrong' => array_map('strval', $entry['wrong'] ?? [])];
        }
    }
    return $catalogs;
}

/**
 * Applies the vocabulary to a translated mapping (resname => text). A unit
 * whose whole source text equals a vocabulary text of the source language
 * (ignoring case and surrounding whitespace) gets the vocabulary text of the
 * target language. Slug units are left to fix-slugs, which runs after this.
 *
 * Longer texts are never changed: a vocabulary phrase inside a longer text may
 * be inflected or need other words around it. The problems file lists, for
 * review, every unit whose source contains a vocabulary phrase anywhere and
 * whose translation contains one of that phrase's `wrong` renderings anywhere
 * (both ignoring case).
 */
function applyTerms(array $target, string $targetLang, array $source, string $sourceLang, array $catalogs, ?string $problemsFile = null) : array
{
    $sourceTerms = $catalogs[$sourceLang] ?? [];
    $targetTerms = $catalogs[$targetLang] ?? [];

    $idsBySourceText = [];
    foreach ($sourceTerms as $id => $entry) {
        if (isset($targetTerms[$id])) $idsBySourceText[mb_strtolower(trim($entry['text']))] = $id;
    }

    $problems = [];
    foreach ($source as $resname => $sourceText) {
        if (!is_string($sourceText) || isSlugResname($resname)) continue;
        $id = $idsBySourceText[mb_strtolower(trim($sourceText))] ?? null;
        if (null !== $id) {
            $target[$resname] = $targetTerms[$id]['text'];
            continue;
        }
        $targetText = $target[$resname] ?? '';
        if (!is_string($targetText) || '' === $targetText) continue;
        foreach ($idsBySourceText as $phrase => $termId) {
            if (!str_contains(mb_strtolower($sourceText), $phrase)) continue;
            foreach ($targetTerms[$termId]['wrong'] as $wrong) {
                if (str_contains(mb_strtolower($targetText), mb_strtolower($wrong))) {
                    $problems[$resname][] = [
                        'term' => $termId,
                        'wrong' => $wrong,
                        'expected' => $targetTerms[$termId]['text'],
                        'source' => $sourceText,
                        'target' => $targetText,
                    ];
                }
            }
        }
    }

    if (null !== $problemsFile && count($problems) > 0) {
        @mkdir(dirname($problemsFile), 0755, true);
        file_put_contents($problemsFile, json_encode($problems, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    }

    return $target;
}

const VOCABULARY_DIR_ENV_VAR = 'CVT_VOCABULARY_DIR';

/** The catalogs in the directory named by CVT_VOCABULARY_DIR; exits with a message when it is not set. */
function vocabularyFromEnvironment() : array
{
    $dir = getenv(VOCABULARY_DIR_ENV_VAR);
    if (false === $dir || '' === $dir) {
        fwrite(STDERR, VOCABULARY_DIR_ENV_VAR . " is not set; it names the directory of vocabulary catalogs (<locale>.yaml)\n");
        exit(1);
    }
    return loadTermCatalogs($dir);
}

function cmd_apply_terms(array $target, string $targetLang, array $source, string $sourceLang, ?string $problemsFile = null) : string
{
    $result = var_export(applyTerms($target, $targetLang, $source, $sourceLang, vocabularyFromEnvironment(), $problemsFile), true);
    return "<?php\nreturn $result;\n";
}
