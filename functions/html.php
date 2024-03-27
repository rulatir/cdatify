<?php

use Matecat\XliffParser\XliffParser;

function cmd_html(string $inputString, bool $useLongTags, bool $mergeDuplicates) : string
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

    $result = array_map(
        function ($unit) use ($shortcodeConverter) {
            $content = htmlspecialchars_decode($unit['source']['raw-content']);
            echo "";
            return [
                'id' => $unit['attr']['id'],
                'resname' => $unit['attr']['resname'],
                'value' => $shortcodeConverter->sc2html(htmlspecialchars_decode($unit['source']['raw-content']))
            ];
        },
        array_merge(...array_map(fn($f) => array_values($f['trans-units']),$files))
    );

    $templateParams = [
        '%%%original%%%' => $original,
        '%%%source-language%%%' => $sourceLanguage,
        '%%%target-language%%%' => $targetLanguage,
        '%%%use-long-tags%%%' => $useLongTags ? "yes" : "no"
    ];
    /** @noinspection PhpParamsInspection */
    return
        $mergeDuplicates
            ? renderTemplateWithDeduplication(...[...htmlTemplateWithDeduplication($templateParams), mergeDuplicates($result)])
            : renderTemplate(...[...htmlTemplate($templateParams), $result]);
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

