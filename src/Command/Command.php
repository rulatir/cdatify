<?php

namespace Rulatir\Cdatify\Command;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use Garden\Cli\Args;

abstract class Command
{
    protected string $inputSpec;
    protected string $outputSpec;

    public function __construct(protected Args $args)
    {
        $this->inputSpec = $args->getArg('input', '-');
        $output = '-'===$this->inputSpec ? '-' : static::buildDefaultOutputPath($this->inputSpec);
        $this->outputSpec = $args->getOpt('output',$output);
    }

    public function getInputSpec(): string
    {
        return $this->inputSpec;
    }

    public function getOutputSpec(): string
    {
        return $this->outputSpec;
    }

    public abstract function transformTextNode(DOMDocument $dom, DOMElement $parent, DOMText $node) : ?DOMNode;

    public static function make(Args $args) : Command
    {
        $fqcn = preg_replace("~\\\\Command$~", '\\'.ucfirst(strtolower($args->getCommand())), self::class);
        if (!class_exists($fqcn)) {
            throw new \RuntimeException("No class defined to handle {$args->getCommand()} command");
        }
        if (!is_a($fqcn, self::class, true)) {
            throw new \RuntimeException("$fqcn does not extend Command");
        }
        return new $fqcn($args);
    }

    protected abstract function buildDefaultOutputPath(string $inputPath) : string;
}