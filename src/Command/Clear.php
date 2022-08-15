<?php

namespace Rulatir\Cdatify\Command;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use Rulatir\Cdatify\Command\Traits\DefaultSuffix;

class Clear extends Command
{
    use DefaultSuffix;
    public function transformTextNode(DOMDocument $dom, DOMElement $parent, DOMText $node): ?DOMNode
    {
        if ('target'===$parent->tagName) {
            $parent->removeAttribute('state');
            return $dom->createTextNode('');
        }
        return $dom->createTextNode($node->textContent);
    }

    protected function getDefaultOutputSuffix(): string
    {
        return 'CLEAR';
    }
}