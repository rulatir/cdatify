<?php

namespace Rulatir\Cdatify\Command\Traits;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

trait ProcessesHTML
{
    public function transformTextNode(DOMDocument $dom, DOMElement $parent, DOMText $node): ?DOMText
    {
        if (!in_array($parent->tagName,['source','target'])) return $node;
        $nodeDom = new DOMDocument();
        $html = <<<HTML
<!DOCTYPE html>
<html lang>
    <head>
        <title>Host document</title>
        <meta charset="UTF-8">
    </head>
    <body id="the-body">%%%</body>
</html>
HTML;

        $theString = html_entity_decode($node->textContent);
        $nodeDom->loadHTML(str_replace('%%%',$theString,$html));
        $body = $nodeDom->getElementById('the-body');
        $this->transformElement($nodeDom, $body);
        return $dom->createTextNode($this->innerHTML($nodeDom, $body));
    }

    protected function innerHTML(DOMDocument $nodeDom, DOMElement $body) : string
    {
        return implode("",array_map(
            fn(DOMNode $node) : string => $nodeDom->saveHTML($node),
            iterator_to_array($body->childNodes)
        ));
    }

    protected abstract function transformElement(DOMDocument $nodeDom, DOMElement $element) : void;
}