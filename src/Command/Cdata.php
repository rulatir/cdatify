<?php

namespace Rulatir\Cdatify\Command;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use Garden\Cli\Args;
use Rulatir\Cdatify\Command\Traits\DefaultSuffix;

class Cdata extends Command
{
    use DefaultSuffix;
    public function __construct(Args $args)
    {
        parent::__construct($args);
    }

    public function transformTextNode(DOMDocument $dom, DOMElement $parent, DOMText $node): DOMNode
    {
        return $dom->createCDATASection($node->textContent);
    }

    protected function getDefaultOutputSuffix(): string
    {
        return 'CDATA';
    }
}