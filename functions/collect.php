<?php

/**
 * Collects a translation from a Loco PHP export archive (Zend PHP array): for
 * every locale in the archive other than the source, including locales the
 * vocabulary (CVT_VOCABULARY_DIR) has no catalog for, applies the vocabulary,
 * then fixes the slugs, so the slugs follow the corrected titles. Writes each
 * collected locale to `<outDir>/<file name in the archive>`, e.g.
 * `palac-lomnica-en-GB.php`; the archive is not changed.
 *
 * An asset is affected when the vocabulary corrected its translation in any
 * locale. A translation of an affected asset that the vocabulary has no rule
 * for in its locale would be marked fuzzy; the PHP export cannot store that,
 * so these go to `<problemsDir>/fuzzy.json`: locale => list of {asset (the
 * Loco asset ID, which is the export's key), term, source, target}.
 *
 * Problems go to `<problemsDir>/<locale>.terms.json` (see applyTerms()) and
 * `<problemsDir>/<locale>.slugs.json`. Reports of an earlier run are removed
 * first, so the directory shows this run only.
 *
 * @param string $sourceLang language of the source locale (`pl` matches `pl-PL`)
 * @return string nothing; progress goes to stderr
 */
function cmd_collect(string $archivePath, string $sourceLang, array $looseMatchPatterns, string $outDir, string $problemsDir) : string
{
    $in = new ZipArchive();
    if (true !== $in->open($archivePath, ZipArchive::RDONLY)) {
        throw new RuntimeException("cannot open $archivePath as a zip archive");
    }

    $mappings = [];
    for ($i = 0; $i < $in->numFiles; $i++) {
        $name = $in->getNameIndex($i);
        if (preg_match('#/locales/([^/]+)/[^/]+\.php$#', $name, $m)) {
            $mappings[$m[1]] = ['entry' => $name, 'data' => eval('?>' . $in->getFromIndex($i))];
        }
    }

    $sourceLocales = array_filter(array_keys($mappings), fn($locale) => localeLanguage($locale) === $sourceLang);
    if (1 !== count($sourceLocales)) {
        throw new RuntimeException("expected one $sourceLang locale in $archivePath, found " . count($sourceLocales));
    }
    $sourceLocale = reset($sourceLocales);
    $source = $mappings[$sourceLocale]['data'];
    $catalogs = vocabularyFromEnvironment();

    @mkdir($problemsDir, 0755, true);
    array_map('unlink', array_merge(
        glob("$problemsDir/*.terms.json") ?: [],
        glob("$problemsDir/*.slugs.json") ?: [],
        glob("$problemsDir/fuzzy.json") ?: []
    ));

    $corrected = [];
    $affected = [];
    foreach ($mappings as $locale => $mapping) {
        if ($locale === $sourceLocale) continue;
        $before = $mapping['data'];
        $after = applyTerms($before, localeLanguage($locale), $source, $sourceLang, $catalogs, "$problemsDir/$locale.terms.json");
        foreach ($after as $resname => $text) {
            if ($text !== ($before[$resname] ?? null)) $affected[$resname] = true;
        }
        $corrected[$locale] = $after;
    }

    $termIds = sourceTermIds($source, $sourceLang, $catalogs);
    $fuzzy = [];
    foreach ($corrected as $locale => $target) {
        foreach (array_keys($affected) as $resname) {
            $termId = $termIds[$resname];
            if (isset($catalogs[localeLanguage($locale)][$termId])) continue;
            $fuzzy[$locale][] = ['asset' => $resname, 'term' => $termId, 'source' => $source[$resname], 'target' => $target[$resname] ?? null];
        }
    }
    if (count($fuzzy) > 0) {
        file_put_contents("$problemsDir/fuzzy.json", json_encode($fuzzy, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    }

    $in->close();

    @mkdir($outDir, 0755, true);
    foreach ($corrected as $locale => $target) {
        $file = rtrim($outDir, '/') . '/' . basename($mappings[$locale]['entry']);
        file_put_contents($file, cmd_fix_slugs($target, $locale, $source, $sourceLocale, $looseMatchPatterns, "$problemsDir/$locale.slugs.json"));
        fwrite(STDERR, "collected $locale → $file" . (isset($fuzzy[$locale]) ? ', ' . count($fuzzy[$locale]) . ' fuzzy' : '') . "\n");
    }
    return '';
}

/**
 * Asset => vocabulary ID, for every non-slug asset whose whole source text
 * equals a vocabulary text of the source language (case and surrounding space
 * ignored), as applyTerms() matches them.
 */
function sourceTermIds(array $source, string $sourceLang, array $catalogs) : array
{
    $idsByText = [];
    foreach ($catalogs[$sourceLang] ?? [] as $id => $entry) $idsByText[mb_strtolower(trim($entry['text']))] = $id;
    $result = [];
    foreach ($source as $resname => $text) {
        if (!is_string($text) || isSlugResname($resname)) continue;
        $id = $idsByText[mb_strtolower(trim($text))] ?? null;
        if (null !== $id) $result[$resname] = $id;
    }
    return $result;
}

/** `en-GB` → `en` */
function localeLanguage(string $locale) : string
{
    return preg_split('/[-_]/', "$locale-")[0];
}

/** `…/php.zip` → `…/php.problems` */
function collectProblemsDir(string $archivePath) : string
{
    return preg_replace('/(\.zip)?$/i', '.problems', $archivePath, 1);
}
