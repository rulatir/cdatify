<?php

use Matecat\XliffParser\XliffParser;

function cmd_improvement(string $worse, string $better) : string
{
    ['data'=>$worseData] = parseTranslationHTML($worse);
    ['data'=>$betterData,'mergeDuplicates'=>$merge] = $betterData = parseTranslationHTML($better);
    $worseUnits = indexUnits($worseData);
    $betterUnits = indexUnits($betterData);
    $diffs = [];
    foreach($betterUnits as $resname => $betterUnit)
        if(($worseUnits[$resname]['value'] ?? null) !== $betterUnit['value'])
            $diffs[$resname]=$betterUnit;

    $templateParams = [
        '%%%original%%%' => 'improved translation',
        '%%%source-language%%%' => $betterData['sourceLanguage'],
        '%%%target-language%%%' => $betterData['targetLanguage'],
        '%%%use-long-tags%%%' => $betterData['useLongTags'] ? "yes" : "no"
    ];
    /** @noinspection PhpParamsInspection */
    return
        $merge
            ? renderTemplateWithDeduplication(...[...htmlTemplateWithDeduplication($templateParams), mergeDuplicates($diffs)])
            : renderTemplate(...[...htmlTemplate($templateParams), $diffs]);

}

function indexUnits(array $data) : array
{
    $result = [];
    foreach($data as $unit) {
        $result[$unit['resname']]=$unit;
    }
    return $result;
}