<?php

namespace Rulatir\Cdatify\Command;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use Ahc\Cli\Input\Command as AhcCommand;

abstract class Command
{
    protected string $inputSpec;
    protected string $outputSpec;

    public function __construct(protected AhcCommand $args)
    {
        $values = $this->args->values(true);
        $pos = $this->args->args();
        $this->inputSpec = $values['input'] ?? ($pos[0] ?? '-');
        $output = '-'===$this->inputSpec ? '-' : static::buildDefaultOutputPath($this->inputSpec);
        $this->outputSpec = $values['output'] ?? $output;
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

    public static function make(AhcCommand $args) : Command
    {
        $fqcn = preg_replace("~\\\\Command$~", '\\'.ucfirst(strtolower($args->name())), self::class);
        if (!class_exists($fqcn)) {
            throw new \RuntimeException("No class defined to handle {$args->name()} command");
        }
        if (!is_a($fqcn, self::class, true)) {
            throw new \RuntimeException("$fqcn does not extend Command");
        }
        return new $fqcn($args);
    }

    protected abstract function buildDefaultOutputPath(string $inputPath) : string;
}