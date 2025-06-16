<?php

use Rulatir\Cdatify\Utility;
use Rulatir\Cdatify\WC\WC;

function cmd_whtml(string $inputString) : string
{

    $dom = html5qp($inputString);
    $dom->find('style')->remove();
    $style = <<<CSS
    * {
        font-family: "Liberation Sans", Arial, sans-serif;
        font-size: 10pt;
        line-height: 1em;
    }
    table {
        border-collapse: collapse;
        border-spacing: 0;
    }
    p, table {
        margin: 1em 0;
    }
    table.messages {
        width: 100%;
    }
    table.messages, table.messages > tbody > tr > th, table.messages > tbody > tr > td {
        border: 1px solid black;
        padding: 0.2em;
    }
    table.stats {
        width: 100%;
    }
    table.stats > tbody > tr > th, table.stats > tbody > tr > td {
        white-space: nowrap;
        line-height: 1em;
        margin: 0;
        padding: 0;
    }
    table.stats > tbody > tr > th {
        text-align: right;
        width: 24%;
    }
CSS;
    $dom->find('head')->append("<style>$style</style>");

    $dom->find('body > table')->addClass('messages');
    $dom->find('body > table > thead')->remove();

    //remove cells that are neither source nor target
    $dom->find('body > table > tbody > tr > td:not(.source,.target)')->remove();
    $dom->find('body > table > tbody > tr')->removeAttr('id')->removeAttr('title');
    $dom->find('html')->removeAttr('xmlns');

    //unescape HTML in table cells

    $dom->find('body > table > tbody > tr > td')->eachQ(function ($n, $elt) {
        $pc = Utility::documentify('<div></div>');
        $div = $pc->find('> div');
        $outerHTML = Utility::innerHTML($elt->get(0));
        $unescapedHTML =
            str_replace(
                ['&lt;', '&gt;'],
                ['<', '>'],
                $outerHTML
            );
        $div->append(Utility::documentify($unescapedHTML)->contents());
        $elt->contents()->remove();
        $elt->append($div);
    });

    $data = [];
    $dom->find('body > p')->eachQ(function($n, $elt) use (&$data) {
        [$k,$v] = array_map('trim',explode(':',$elt->text(),2));
        $data[$k]=$v;
    })->remove();
    $wc = new WC();
    $wc->wc($dom->find('body > table'));
    $data = array_merge($data,[
        'words' => $wc->words(),
        'visible_chars' => $wc->visible_chars(),
        'all_chars' => $wc->all_chars(),
        'pages' => ceil($wc->all_chars()/1800)
    ]);
    $w = <<<HTML
<table class="stats">
    <tbody>
        <tr>
            <th><div>Projekt:&nbsp;</div></th><td><div>{$data['Project']}</div></td>
        </tr>
        <tr>
            <th><div>Locale:&nbsp;</div></th><td><div>{$data['Locale']}</div></td>
        </tr>
        <tr>
            <th><div>Wyeksportowano:&nbsp;</div></th><td><div>{$data['Exported at']}</div></td>
        </tr>
        <tr>
            <th><div>Słów:&nbsp;</div></th><td><div>{$data['words']}</div></td>
        </tr>
        <tr>
            <th><div>Znaków bez spacji:&nbsp;</div></th><td><div>{$data['visible_chars']}</div></td>
        </tr>
        <tr>
            <th><div>Znaków ze spacjami:&nbsp;</div></th><td><div>{$data['all_chars']}</div></td>
        </tr>
        <tr>
            <th><div>Stron standardowych:&nbsp;</div></th><td><div>⌈{$data['all_chars']} ÷ 1800⌉ = <strong>{$data['pages']}</strong></div></td>
        </tr>
    </tbody>
</table>
<p>&nbsp;</p>
<p>
    <strong>UWAGA:</strong> metody liczenia znaków i słów przez różne edytory tekstu mogą
    się nieznacznie różnić, jednak zazwyczaj nie więcej niż o 1-2%. Powyższe wartości są orientacyjne.
</p>
HTML;

    $dom->find('body')->prepend(Utility::documentify($w)->contents());
    $html = $dom->document()->saveHTML();
    $html = str_replace('<html xmlns="http://www.w3.org/1999/xhtml">','<html>',$html);
    return html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}
