<?php

use DOMWrap\Comment;
use DOMWrap\Document;
use DOMWrap\Element;
use DOMWrap\NodeList;
use DOMWrap\ProcessingInstruction;
use DOMWrap\Text;
use FluentDOM\Query;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use QueryPath\DOMQuery;
use Rulatir\Cdatify\Shortcode\AskerOfQuestionsCLI;
use Rulatir\Cdatify\Shortcode\ParameterTranslationDeciderAnswerFile;
use Rulatir\Cdatify\Shortcode\ShortcodeConverter;
use Rulatir\Cdatify\Utility;

if (!function_exists('makeShortcodeConverter')) {
    function makeShortcodeConverter(bool $useLongTags=false) : ShortcodeConverter
    {
        $configDir = getenv('HOME')."/.local/cdatify/shortcode-schemas";
        @mkdir($configDir, 0755, true);
        return new ShortcodeConverter(
            new ParameterTranslationDeciderAnswerFile(
                'zuu-cms-shortcodes',
                new Filesystem(new LocalFilesystemAdapter($configDir)),
                new AskerOfQuestionsCLI()
            ),
            $useLongTags
        );
    }
}

if (!function_exists('FQ')) {
    function FQ(null|Document|NodeList|DOMNode|string $input=null) : Document|NodeList {
        if ($input instanceof Document || $input instanceof NodeList) {
            return $input;
        }
        if (
            $input instanceof Element
            || $input instanceof Text
            || $input instanceof ProcessingInstruction
            || $input instanceof Comment
        ) {
            return $input->collection();
        }
        if ($input instanceof DOMNode) {
            return new NodeList($input->ownerDocument,[$input]);
        }
        if (is_string($input)) {
            $doc = Utility::mkdoc();
            $doc->loadHTML($input);
            return $doc->find('body')->contents();
        }
        return new NodeList(Utility::mkdoc());
    }
}

if (!function_exists('pieces')) {
    /**
     * @return Generator<DOMQuery>
     */
    function pieces(DOMQuery $query): \Generator
    {
        for($i=0; $i<$query->length; ++$i) yield $query->eq($i);
    }
}

if (!function_exists('getParentElement')) {
    function getParentElement(DOMElement $elt) : ?DOMElement {
        $parent = $elt->parentNode;
        return $parent instanceof DOMElement ? $parent : null;
    }
}

if (!function_exists('QQ')) {
     function QQ(string $html) : DOMQuery {
         $id = 'cdatify-processing-container';
         return html5qp("<section id=\"$id\">$html</section>")->find("#$id")->contents();
     }
}

require_once __DIR__."/../functions/fix-slugs.php";
require_once __DIR__."/../functions/html.php";
require_once __DIR__."/../functions/slugs.php";
require_once __DIR__."/../functions/templates.php";
require_once __DIR__."/../functions/templating.php";
require_once __DIR__."/../functions/xlf.php";

