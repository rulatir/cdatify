<?php

function hydrate(string $tpl, array $data) : string
{
    return str_replace(
        array_map(fn(string $k) : string => "%%%$k%%%", array_keys($data)),
        array_values($data),
        $tpl
    );
}

function renderTemplate(string $docTpl, string $itemTpl, array $items) : string
{
    $renderedItems = array_map(
        fn(array $item) : string => hydrate($itemTpl, $item),
        $items
    );
    return str_replace('%%%ITEMS%%%',implode("\n",$renderedItems),$docTpl);
}

function renderTemplateWithDeduplication(string $docTpl, string $itemTpl, string $idTpl, array $items) : string
{
    $renderedItems = array_map(
        fn(array $itemWithRenderedIds) : string => hydrate($itemTpl, $itemWithRenderedIds),
        array_map(
            fn(array $item) : array => [
                ...$item,
                'IDS' => implode("            \n", array_map(fn(array $id) : string => hydrate($idTpl, $id), $item['IDS']))
            ],
            $items
        )
    );
    return str_replace('%%%ITEMS%%%',implode("\n",$renderedItems),$docTpl);
}
