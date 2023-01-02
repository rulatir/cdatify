<?php

namespace Rulatir\Cdatify\Shortcode;

use DOMDocumentFragment;
use DOMNode;
use IvoPetkov\HTML5DOMDocument;
use IvoPetkov\HTML5DOMElement;
use Rulatir\Cdatify\Shortcode\Contracts\ParameterTranslationDecider;
use Thunder\Shortcode\Parser\RegularParser;
use Thunder\Shortcode\Shortcode\ParsedShortcodeInterface;

class ShortcodeConverter
{

    public function __construct(protected ParameterTranslationDecider $decider)
    {
    }

    public function sc2html(?string $htmlWithShortcodes) : ?string
    {
        if (null===$htmlWithShortcodes) return null;
        $parser = new RegularParser();
        $assembler = new Assembler($htmlWithShortcodes);
        $shortcodes = $parser->parse($htmlWithShortcodes);
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
        return $this->renderConvertedShortcode($name, $params, $this->sc2html($shortcode->getContent()));
    }

    protected function convertShortcodeParameter(
        string $shortcodeName,
        string $paramName,
        string $shortcodeText,
        ?string $value
    ) : string
    {
        $nameTag = "<scparamname translate=\"no\">$paramName</scparamname>";
        $valueTag =
            null === $value
                ? ""
                : (
                    $this->decider->shouldTranslate($shortcodeName, $paramName, $shortcodeText)
                        ? "<scparamvalue>$value</scparamvalue>"
                        : "<scparamvalue translate=\"no\">$value</scparamvalue>"
                );

        return "<scparam>{$nameTag}{$valueTag}</scparam>";
    }

    protected function renderConvertedShortcode(string $name, array $convertedParams, ?string $convertedContent) : string
    {
        return implode("", array_filter([
            "<sc><scname translate=\"no\">$name</scname>",
            ...$convertedParams,
            $convertedContent ? "<sccontent>$convertedContent</sccontent>" : null,
            "</sc>"
        ]));
    }

    public function html2sc(string $htmlWithEscapedShortcodes) : string
    {
        $document = new HTML5DOMDocument();
        $document->loadHTML(implode("",[
            '<!DOCTYPE html>',
            '<html lang="en">',
            '<head><meta charset="utf-8"><title>Processing container</title></head>',
            '<body><section id="processing-container">'.$htmlWithEscapedShortcodes.'</section></body>',
            '</html>'
        ]));
        /** @var HTML5DOMElement $container */
        $container = $document->getElementById('processing-container');
        $this->unconvertChildren($document, $container);
        return $container->innerHTML;
    }

    protected function unconvertChildren(HTML5DOMDocument $document, HTML5DOMElement $element) : void
    {
        foreach(iterator_to_array($element->childNodes->getIterator()) as $childNode) {
            if (null!==$replacement=$this->unconvertElement($document, $childNode)) {
                $childNode->parentNode->replaceChild($childNode, $replacement);
            }
        }
    }

    protected function unconvertElement(HTML5DOMDocument $document, ?DOMNode $node) : ?DOMNode
    {
        if ($node instanceof HTML5DOMElement && $node->tagName==='sc') {
            return $this->buildUnconvertedShortcode($document, $node);
        }
        return null;
    }

    protected function buildUnconvertedShortcode(HTML5DOMDocument $document, HTML5DOMElement $sc) : DOMDocumentFragment
    {
        $name = $sc->querySelector('> scparamname')->getTextContent();
        $params = [];
        foreach($sc->querySelectorAll('> scparam') as $paramElement) {
            $params[$paramElement->querySelector('> scparamname')->getTextContent()]
                = $paramElement->querySelector('> scparamvalue')?->getTextContent() ?? true;
        }
        $haveContentElement = null !== $contentElement = $sc->querySelector('> sccontent');
        if ($haveContentElement) {
            $this->unconvertChildren($document, $contentElement);
        }
        return $this->renderUnconvertedShortcode($document, $name, $params, $contentElement);
    }

    protected function renderUnconvertedShortcode(
        HTML5DOMDocument $document,
        string $shortcodeName,
        array $parameters,
        ?HTML5DOMElement $contentElement
    ) : DOMDocumentFragment
    {
        $fragment = $document->createDocumentFragment();
        if ($contentElement) foreach($fragment->childNodes as $child) $fragment->appendChild($child->cloneNode(true));
        $renderedParameters = [];
        foreach($parameters as $name=>$value) {
            $renderedParameter = $name;
            if ($value !== true) $renderedParameter .= "=\"$value\"";
            $renderedParameters[] = $renderedParameter;
        }
        $openingShortcode = implode("",[
            "[",
            implode(" ",[$shortcodeName, ...$renderedParameters]),
            null === $contentElement ? "/" : "",
            "]"
        ]);
        $fragment->prepend($document->createTextNode($openingShortcode));
        if ($contentElement) $fragment->append($document->createTextNode("[/$shortcodeName]"));
        return $fragment;
    }
}