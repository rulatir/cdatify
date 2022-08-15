<?php

namespace Rulatir\Cdatify\Command;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use Rulatir\Cdatify\Command\Traits\ProcessesHTML;

class Lws extends ReversibleCommand
{

    use ProcessesHTML;

    protected function getDefaultOutputSuffix(): string
    {
        return 'LWS';
    }

    protected function transformElement(DOMDocument $nodeDom, DOMElement $element): void
    {
        $hadChildren = false;
        foreach(iterator_to_array($element->childNodes) as $child) {
            $hadChildren = true;
            if ($child instanceof DOMElement) {
                if ($this->remove && 'lws'===strtolower($child->tagName)) {
                    $element->removeChild($child);
                }
                if ('lws'!==strtolower($child->tagName)) {
                    $this->transformElement($nodeDom, $child);
                }
            }
        }
        if ($hadChildren && !$this->remove) {
            $lws = $nodeDom->createElement('lws');
            $lws->append(' ');
            $lws->setAttribute('val', "");
            $element->prepend($lws);
        }
    }
}