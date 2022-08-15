<?php

namespace Rulatir\Cdatify\Command;

use DOMDocument;
use DOMDocumentFragment;
use DOMElement;
use DOMNode;
use DOMText;
use Rulatir\Cdatify\Command\Traits\DefaultSuffix;
use Rulatir\Cdatify\Command\Traits\ProcessesHTML;

class Spc extends ReversibleCommand
{
    use ProcessesHTML;

    protected function transformElement(DOMDocument $nodeDom, DOMElement $element) : void
    {
        if ($this->remove) {
            $this->unwrapAllWhitespace($nodeDom, $element);
        }
        else {
            $this->wrapAllWhitespace($nodeDom, $element);
        }
    }

    protected function unwrapAllWhitespace(DOMDocument $nodeDom, DOMElement $element) : void
    {
        foreach(iterator_to_array($element->childNodes) as $child) {
            $replacement = null;
            if ($child instanceof DOMElement) {
                if('spc' === strtolower($child->tagName)) {
                    $replacement = $nodeDom->createTextNode($child->getAttribute('val'));
                }
                else {
                    $this->transformElement($nodeDom, $child);
                }
            }
            elseif($child instanceof DOMText) {
                $replacement = $nodeDom->createTextNode(
                    preg_replace("/^\\s*/","",preg_replace("/\\s*$/","",$child->textContent))
                );
            }
            if ($replacement) {
                $element->replaceChild($replacement, $child);
            }
        }
    }

    protected function wrapAllWhitespace(DOMDocument $nodeDom, DOMElement $element) : void
    {
        foreach(iterator_to_array($element->childNodes) as $node) {
            $replacement = null;
            if ($node instanceof DOMText) {
                $replacement = $this->wrapWhitespace($nodeDom, $element, $node);
            }
            elseif ($node instanceof DOMElement) {
                $this->transformElement($nodeDom,$node);
            }
            if($replacement) {
                $element->insertBefore($replacement,$node);
                $node->remove();
            }
        }
    }

    protected function wrapWhitespace(DOMDocument $nodeDom, DOMElement $parent, DOMText $text) : DOMDocumentFragment
    {
        $result = $nodeDom->createDocumentFragment();
        $pieces = preg_split("/(\\s+)/", $text->textContent, -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach($pieces as $piece) {
            if (preg_match("/^\\s+$/", $piece)) {
                /** @noinspection PhpUnhandledExceptionInspection */
                $spc = $nodeDom->createElement('spc');
                $spc->setAttribute('val', $piece);
                $spc->appendChild($nodeDom->createTextNode(' '));
                $result->appendChild($spc);
            }
            else {
                $result->appendChild($nodeDom->createTextNode($piece));
            }
        }
        return $result;
    }

    protected function getDefaultOutputSuffix(): string
    {
        return 'SPC';
    }
}