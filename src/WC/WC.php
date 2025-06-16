<?php

namespace Rulatir\Cdatify\WC;

use DOMElement;
use DOMText;
use QueryPath\DOMQuery;

class WC
{
    const INLINE_TAGS = ['a','abbr','acronym','b','bdi','bdo','big','br','button','cite','code','data','datalist','del','dfn','em','i','img','input','ins','kbd','label','map','mark','meter','noscript','object','output','picture','progress','q','ruby','s','samp','script','select','slot','small','span','strong','sub','sup','svg','template','textarea','time','u','tt','var','wbr'];
    const WORD = '/\b[\p{L}\p{N}](?:[\p{L}\p{N}—–-]*[\p{L}\p{N}])?\b/u';
    protected string $text="";
    protected string $sep="";
    public function wc(DOMQuery $elt) : void
    {
        $children = $elt->contents();
        foreach($children->get() as $node) {
            if ($node instanceof DOMText) {
                $text = $node->textContent;
                if (strlen(trim($text))) {
                    $this->text .= $this->sep.$text;
                    $this->sep="";
                }
            } elseif ($node instanceof DOMElement) {
                $isInline = in_array($node->tagName,self::INLINE_TAGS);
                $this->sep = $isInline ? '' : "\n";
                $this->wc(QQ($node));
                $this->sep = $isInline ? '' : "\n";
            }
        }
    }

    public function words() : int
    {
        preg_match_all(self::WORD,$this->text,$matches);
        return count($matches[0]);
    }

    public function all_chars() : int
    {
        return mb_strlen($this->text);
    }

    public function visible_chars() : int
    {
        preg_match_all(self::WORD,$this->text,$matches);
        return strlen(implode("",$matches[0]));
    }
}