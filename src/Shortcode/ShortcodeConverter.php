<?php

namespace Rulatir\Cdatify\Shortcode;

use DOMAttr;
use DOMWrap\Element;
use DOMWrap\Document;
use DomWrap\NodeList;
use Rulatir\Cdatify\Shortcode\Contracts\ParameterTranslationDecider;
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

    public function __construct(protected ParameterTranslationDecider $decider, protected bool $useLongTags=false)
    {
    }

    public function getTagMap() : array
    {
        return $this->useLongTags ? self::LONG_TAGS : self::SHORT_TAGS;
    }

    public function html2avat(?string $html) : ?string
    {
        if (null===$html) return null;
        $container = $this->documentify($html);
        $this->avatifyChildren($container);
        return $this->undocumentify($container);
    }

    public function avat2html(?string $avat) : ?string
    {
        if (null===$avat) return null;
        $container = $this->documentify($avat);
        $this->unavatifyChildren($container);
        $result = $this->undocumentify($container);
        return $result;
    }

    protected function avatifyChildren(Document|NodeList $container) : void
    {
        foreach(pieces($container->children()) as $node) {
            $node->substituteWith($this->avatify($node));
        }
    }

    protected function unavatifyChildren(Document|NodeList $container) : void
    {
        foreach(pieces($container->children()) as $node) {
            $node->substituteWith($this->unavatify($node));
        }
    }

    protected function avatify(Document|NodeList $elt) : Document|NodeList
    {
        $doc = $elt[0]->ownerDocument;
        $this->avatifyChildren($elt);
        $translate =  $elt->attr('translate')==='no' ? ' translate="no"' : "";
        $chunks = ["<ft-t{$translate}><ft-n translate=\"no\">{$elt[0]->tagName}</ft-n>"];
        foreach($elt->attributes as $name=>$attr) {
            $translate = $name==='title' ? "" : ' translate="no"';
            $chunks[]="<ft-a><ft-an translate='\"no\"'>$name</ft-an><ft-av{$translate}>{$attr->value}</ft-av></ft-a>";
        }
        if ("" !== $html=$elt->html())
            $chunks[]="<ft-c>$html</ft-c>";
        return FQ($doc->create(implode("",[...$chunks,'</ft-t>'])));
    }

    protected function unavatify(Document|NodeList $elt) : Document|NodeList
    {
        $this->unavatifyChildren($elt);
        if ('ft-t'!==$elt[0]->tagName) return $elt;
        $allChildren = $elt->children();
        $children = $elt->children('ft-n');
        $filtered = $allChildren->filter('ft-n');
        $first = $children->eq(0);
        $tagName = $first->textContent;
        $replacement = $elt->create("<$tagName></$tagName>");
        /** @var Element $attributeNode */
        foreach(pieces($elt->find('fa','child::')) as $attributeNode) {
            $replacement->attr(
                $attributeNode->find('ft-an','child::')->text(),
                $attributeNode->find('ft-av','child::')->text()
            );
        }
        $elt->find('ft-c','child::')->contents()->detach()->appendTo($replacement);
        return $replacement;
    }

    public function sc2html(?string $htmlWithShortcodes) : ?string
    {
        if (null===$htmlWithShortcodes) return null;
        $avatWithShortcodes = $this->html2avat($htmlWithShortcodes);
        $parser = new RegularParser();
        $assembler = new Assembler($avatWithShortcodes);
        $shortcodes = $parser->parse($avatWithShortcodes);
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
        $T=$this->getTagMap();
        $nameTag = "<{$T['scparamname']} translate=\"no\">$paramName</{$T['scparamname']}>";
        $valueTag =
            null === $value
                ? ""
                : (
                    $this->decider->shouldTranslate($shortcodeName, $paramName, $shortcodeText)
                        ? "<{$T['scparamvalue']}>$value</{$T['scparamvalue']}>"
                        : "<{$T['scparamvalue']} translate=\"no\">$value</{$T['scparamvalue']}>"
                );

        return "<{$T['scparam']}>{$nameTag}{$valueTag}</{$T['scparam']}>";
    }

    protected function renderConvertedShortcode(string $name, array $convertedParams, ?string $convertedContent) : string
    {
        $T=$this->getTagMap();
        return implode("", array_filter([
            "<{$T['sc']}><{$T['scname']} translate=\"no\">$name</{$T['scname']}>",
            ...$convertedParams,
            $convertedContent ? "<{$T['sccontent']}>$convertedContent</{$T['sccontent']}>" : null,
            "</{$T['sc']}>"
        ]));
    }

    /**
     * Loads string into document object, wrapping it in a document template if it is not already a full document.
     * Returns the element that serves as the processing container for the content
     */
    protected function documentify(string $html) : Document|NodeList
    {
        $isAlreadyADocument = str_starts_with(mb_strtoupper(mb_substr($html, 0, 50)), '<!DOCTYPE ');
        $document = new Document();
        $document->html(
            $isAlreadyADocument
                ? $html
                : implode("", [
                '<!DOCTYPE html>',
                '<html lang="en">',
                '<head><meta charset="utf-8"><title>Processing container</title></head>',
                '<body><section id="processing-container">' . $html . '</section></body>',
                '</html>'
            ])
        );
        return $document->find($isAlreadyADocument ? 'body' : '#processing-container');
    }

    protected function undocumentify(Document|NodeList $container) : string
    {
        return 'body'===$container[0]->tagName ? $container[0]->ownerDocument->saveHTML() : $container->html();
    }

    public function html2sc(string $htmlWithEscapedShortcodes) : string
    {
        $container = $this->documentify($htmlWithEscapedShortcodes);
        $this->unconvertChildren($container[0]->ownerDocument, $container);
        $unconverted = $this->undocumentify($container);
        return $this->avat2html($unconverted);
    }


    protected function unconvertChildren(Document $document, Document|NodeList $element) : void
    {
        foreach(pieces($element->contents()) as $childNode) {
            if (null!==$replacement=$this->unconvertElement($document, $childNode)) {
                foreach(pieces($replacement) as $piece) $childNode->parent()->insertBefore($piece[0], $childNode[0]);
                $childNode->destroy();
            }
            else if ($childNode[0] instanceof Element) {
                $this->unconvertChildren($document, $childNode);
            }
        }
    }

    protected function unconvertElement(Document $document, Document|NodeList $node) : ?NodeList
    {
        if ($node[0]->tagName===$this->getTagMap()['sc']) {
            return $this->buildUnconvertedShortcode($document, $node);
        }
        return null;
    }

    protected function buildUnconvertedShortcode(Document $document, Document|NodeList $sc) : NodeList
    {
        $T=$this->getTagMap();
        $name = $sc->find($T['scname'],'child::')->first()->text();
        $params = [];
        foreach(pieces($sc->find($T['scparam'],'child::')) as $paramElement) {
            $params[$paramElement->find($T['scparamname'],'child::')->text()]
                = $paramElement->find($T['scparamvalue'],'child::')->text();
        }
        if (($contentElement = $sc->find($T['sccontent'],'child::'))->count()) {
            $this->unconvertChildren($document, $contentElement);
        }
        return $this->renderUnconvertedShortcode($document, $name, $params, $contentElement);
    }

    protected function renderUnconvertedShortcode(
        Document $document,
        string $shortcodeName,
        array $parameters,
        Document|NodeList $contentElement
    ) : NodeList
    {
        $fragment = $document->createDocumentFragment();
        if ($contentElement->count()) foreach(pieces($contentElement->contents()) as $child) {
            $clonedChild = $child[0]->cloneNode(true);
            $fragment->appendChild($clonedChild);
        }
        $renderedParameters = [];
        foreach($parameters as $name=>$value) {
            $renderedParameter = $name;
            if ($value !== true) $renderedParameter .= "=\"$value\"";
            $renderedParameters[] = $renderedParameter;
        }
        $openingShortcode = implode("",[
            "[",
            implode(" ",[$shortcodeName, ...$renderedParameters]),
            "]"
        ]);
        $fragment->prepend($document->createTextNode($openingShortcode));
        if ($contentElement->count()) $fragment->append($document->createTextNode("[/$shortcodeName]"));
        return new NodeList($document, $fragment->childNodes);
    }
}