<?php

use DOMWrap\Document;
use DOMWrap\Element;
use DOMWrap\NodeList;
use FluentDOM\Query;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Rulatir\Cdatify\Shortcode\AskerOfQuestionsCLI;
use Rulatir\Cdatify\Shortcode\ParameterTranslationDeciderAnswerFile;
use Rulatir\Cdatify\Shortcode\ShortcodeConverter;

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
        if ($input instanceof DOMNode) {
            return new NodeList($input->ownerDocument,[$input]);
        }
        if (is_string($input)) {
            $doc = new Document();
            $doc->loadHTML($input);
            return $doc->find('body')->contents();
        }
        return new NodeList(new Document());
    }
}

if (!function_exists('pieces')) {
    /**
     * @return Generator<Document|NodeList>
     */
    function pieces(Document|NodeList $query): \Generator
    {
        foreach(iterator_to_array($query) as $node) yield FQ($node);
    }
}
