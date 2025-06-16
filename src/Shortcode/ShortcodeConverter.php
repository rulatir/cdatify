<?php

namespace Rulatir\Cdatify\Shortcode;

use DOMElement as Elt;
use DOMText as Text;
use QueryPath\DOMQuery as DQ;
use Rulatir\Cdatify\Shortcode\Contracts\ParameterTranslationDecider;
use Rulatir\Cdatify\Utility;
use Thunder\Shortcode\Parser\ParserInterface;
use Thunder\Shortcode\Parser\RegularParser;
use Thunder\Shortcode\Shortcode\ParsedShortcodeInterface;


class ShortcodeConverter
{

    const LONG_TAGS=[
        'sc' => 'sc-t',
        'scname' => 'sc-name',
        'sccontent' => 'sc-content',
        'scparam' => 'sc-param',
        'scparamname' => 'sc-paramname',
        'scparamvalue' => 'sc-paramvalue'
    ];

    const SHORT_TAGS=[
        'sc' => 'sc-t',
        'scname' => 'sc-n',
        'sccontent' => 'sc-c',
        'scparam' => 'sc-p',
        'scparamname' => 'sc-pn',
        'scparamvalue' => 'sc-pv'
    ];

    public function __construct(
        protected ParserInterface $shortcodeParser,
        protected ParameterTranslationDecider $decider,
        protected bool $useLongTags=false
    )
    {
    }

    public function getTagMap() : array
    {
        return $this->useLongTags ? self::LONG_TAGS : self::SHORT_TAGS;
    }

    public function html2avat(?string $html) : ?string
    {
        if (null===$html) return null;
        $container = Utility::documentify($html);
        $this->avatifyChildren($container);
        $container->contents()->filterCallback(fn($n,$e)=>$e instanceof Text)->eachQ(function($n,$t){
            Utility::pasteOver($t, QQ(normalizeTextContentForTranslation($t->text())));
        });
        return Utility::undocumentify($container);
    }

    public function avat2html(?string $avat) : ?string
    {
        if (null===$avat) return null;
        $container = Utility::documentify($avat);
        $this->unavatifyChildren($container);
        $result = Utility::undocumentify($container);
        return $result;
    }

    protected function avatifyChildren(DQ $container) : void
    {
        foreach(pieces($container->children()) as $node) {
            if ($replacement = $this->avatify($node)) {
                Utility::pasteOver($node, $replacement);
            }
        }
    }

    protected function unavatifyChildren(DQ $container) : void
    {
        $container->children()->eachQ(function($n, DQ $node)  {
            if ($node->tag()==='ft-t') {
                Utility::pasteOver($node,$this->unavatify($node));
            }
            else {
                $this->unavatifyChildren($node);
            }
        });
    }

    protected function avatify(DQ $elt) : ?DQ
    {
        $this->avatifyChildren($elt);
        $translate =  $elt->attr('translate')==='no' ? ' translate="no"' : "";
        $chunks = ["<ft-t{$translate}> <ft-n translate=\"no\">{$elt->tag()}</ft-n> "];
        $need = false;
        foreach($elt->attr() as $name=>$attr) {
            if ($this->containsShortcodes($attr)) {
                $need = true;
            }
            $should = $name==='title';
            $translate = $should ? "" : ' translate="no"';
            $chunks[]=" <ft-a> <ft-an translate=\"no\">$name</ft-an> <ft-av{$translate}>{$attr}</ft-av> </ft-a> ";
        }
        if (!$need) return null;
        if ("" !== $html=$elt->innerHTML5())
            $chunks[]=" <ft-c>".normalizeTextContentForTranslation($html)."</ft-c> ";
        return QQ(implode("",[...$chunks,'</ft-t>']));
    }

    protected function containsShortcodes(string $text) : bool
    {
        return count($this->shortcodeParser->parse($text)) > 0;
    }

    protected function unavatify(DQ $elt) : ?DQ
    {
        $this->unavatifyChildren($elt);
        $tagName = $elt->find('> ft-n')->text();
        $replacement = QQ("<$tagName></$tagName>");
        $attributes = $elt->find('> ft-a')
            ->mapQToArr(fn($k,DQ $attr) => [
                $attr->find('> ft-an')->text(),
                $attr->find('> ft-av')->text()]
            );
        foreach($attributes as [$k,$v]) $replacement->attr($k,$v);
        $elt->find('> ft-c')->contents()->detach()->appendTo($replacement);
        return $replacement;
    }

    public function sc2html(?string $htmlWithShortcodes) : ?string
    {
        if (null===$htmlWithShortcodes) return null;
        $avatWithShortcodes = $this->html2avat($htmlWithShortcodes);
        $assembler = new Assembler($avatWithShortcodes);
        $shortcodes = $this->shortcodeParser->parse($avatWithShortcodes);
        foreach($shortcodes as $shortcode) {
            $assembler->appendUpTo($shortcode);
            $assembler->appendReplacement($shortcode, $this->convertShortcode($shortcode));
        }
        
        return $assembler->getText();
    }

    protected function convertShortcode(ParsedShortcodeInterface $shortcode) : string
    {
        $name = $shortcode->getName();
        $params = [];
        foreach($shortcode->getParameters() as $paramName=>$value) {
            $params[] = $this->convertShortcodeParameter($name, $paramName, $shortcode->getText(), $value);
        }
        return $this->renderConvertedShortcode($name, $params, $this->sc2html($shortcode->getContent()), $shortcode->getText());
    }

    protected function convertShortcodeParameter(
        string $shortcodeName,
        string $paramName,
        string $shortcodeText,
        ?string $value
    ) : string
    {
        $T=$this->getTagMap();
        $nameTag = "<{$T['scparamname']} translate=\"no\">$paramName</{$T['scparamname']}>";
        $valueTag =
            null === $value
                ? ""
                : (
                    $this->decider->shouldTranslateParameter($shortcodeName, $paramName, $shortcodeText)
                        ? "<{$T['scparamvalue']}>$value</{$T['scparamvalue']}>"
                        : "<{$T['scparamvalue']} translate=\"no\">$value</{$T['scparamvalue']}>"
                );

        return " <{$T['scparam']}> {$nameTag} {$valueTag} </{$T['scparam']}> ";
    }

    protected function renderConvertedShortcode(
        string $name,
        array $convertedParams,
        ?string $convertedContent,
        string $shortcodeText
    ) : string
    {
        $T=$this->getTagMap();
        $translate =
            $convertedContent && $this->decider->shouldTranslateContent($name, $shortcodeText) ? "" : " translate=\"no\"";
        return implode("", array_filter([
            "<{$T['sc']}> <{$T['scname']} translate=\"no\">$name</{$T['scname']}> ",
            ...$convertedParams,
            $convertedContent ? " <{$T['sccontent']}{$translate}>$convertedContent</{$T['sccontent']}> " : null,
            " </{$T['sc']}>"
        ]));
    }

    public function html2sc(string $htmlWithEscapedShortcodes) : string
    {
        $container = Utility::documentify($htmlWithEscapedShortcodes);
        $this->unconvertChildren($container);
        $unconverted = Utility::undocumentify($container);
        return $this->avat2html($unconverted);
    }


    protected function unconvertChildren(DQ $element) : void
    {
        $element->children()->eachQ(function($n, DQ $child) {
            if ($child->tag()==='sc-t') {
                Utility::pasteOver($child, $this->buildUnconvertedShortcode($child));
            }
            else {
                $this->unconvertChildren($child);
            }
        });
    }

    protected function unconvertElement(DQ $node) : ?DQ
    {
        return $node->is('> '.$this->getTagMap()['sc'])
            ? $this->buildUnconvertedShortcode($node)
            : null;
    }

    protected function buildUnconvertedShortcode(DQ $sc) : DQ
    {
        $T=$this->getTagMap();
        $name = $sc->find('> '.$T['scname'])->first()->text();
        $params = [];
        foreach(pieces($sc->find('> '.$T['scparam'])) as $paramElement) {
            $params[$paramElement->find('> '.$T['scparamname'])->text()]
                = $paramElement->find('> '.$T['scparamvalue'])->text();
        }
        if (($contentElement = $sc->find('> '.$T['sccontent']))->count()) {
            $this->unconvertChildren($contentElement);
        }
        return $this->renderUnconvertedShortcode($name, $params, $contentElement);
    }

    protected function renderUnconvertedShortcode(
        string $shortcodeName,
        array  $parameters,
        DQ     $contentElement
    ) : DQ
    {
        $renderedParameters = [];
        foreach($parameters as $name=>$value) {
            $renderedParameter = $name;
            if ($value !== true) $renderedParameter .= "=\"$value\"";
            $renderedParameters[] = $renderedParameter;
        }
        $shortcode = [
            "[",
            implode(" ",[$shortcodeName, ...$renderedParameters]),
            "]"
        ];
        if ($contentElement->length) {
            $shortcode[] = $contentElement->innerHTML5();
            $shortcode[]="[/$shortcodeName]";
        }
        return QQ(implode("",$shortcode));
    }
}