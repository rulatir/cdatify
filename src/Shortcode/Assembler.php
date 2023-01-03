<?php

namespace Rulatir\Cdatify\Shortcode;

use Thunder\Shortcode\Shortcode\ParsedShortcodeInterface;

class Assembler
{
    protected int $inputOffset=0;
    protected array $chunks = [];
    public function __construct(protected string $originalString)
    {
    }

    public function appendUpTo(ParsedShortcodeInterface $shortcode) : void
    {
        $this->chunks[] = mb_substr(
            $this->originalString,
            $this->inputOffset,
            $shortcode->getOffset() - $this->inputOffset
        );
        $this->inputOffset = $shortcode->getOffset();
    }
    public function appendReplacement(ParsedShortcodeInterface $shortcode, string $replacement) : void
    {
        $this->chunks[] = $replacement;
        $this->inputOffset = $shortcode->getOffset() + mb_strlen($shortcode->getText());
    }

    public function getText() : string
    {
        return implode("",$this->chunks).mb_substr($this->originalString,$this->inputOffset);
    }
}