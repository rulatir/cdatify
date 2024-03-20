<?php

namespace Rulatir\Cdatify;

use DOMWrap\Document;
use PHPUnit\Framework\TestCase;

class DomWrapperSuitability extends TestCase
{
    public function testChildrenFind() : void
    {
        $doc = Utility::mkdoc();
        $html = <<<HTML
<article id="root">
    <div>Not section</div>
    <section>Outer 1</section>
    <div>Not section either</div>
    <section>
        <div>
            <p>Outer 2</p>
            <section>Inner</section>
        </div>
    </section>
    <section>
        <section>
            <div>Outer 3</div>
        </section>
    </section>
</article>
HTML;

        $doc->html($html);
        $list = $doc->find('#root');
        //finds the root
        self::assertEquals('root',$list->attr('id'));

        //finds all sections
        $allItems = $list->find('section');
        self::assertEquals(5,$allItems->count());

        //finds all direct child sections, clumsy method
        $topItems = $list->children()->filter('section');
        self::assertEquals('section',$topItems[0]->tagName);
        self::assertEquals(3,$topItems->count());

        //finds all direct child sections, cool method
        $topItems = $list->find('section','child::');
        self::assertEquals('section',$topItems[0]->tagName);
        self::assertEquals(3,$topItems->count());

        //finds all divs directly under sections
        $topItems = $list->find('section > div');
        self::assertEquals('div',$topItems[0]->tagName);
        self::assertEquals(2,$topItems->count());

        //finds all divs directly under direct child sections, clumsy method
        $theDivs = $list->children()->filter('section')->children()->filter('div');
        self::assertEquals('div',$theDivs[0]?->tagName);
        self::assertEquals(1, $theDivs->count());

        //finds all divs directly under direct child sections, cool method
        $theDivs = $list->find('section > div','child::');
        self::assertEquals('div',$theDivs[0]?->tagName);
        self::assertEquals(1, $theDivs->count());
    }

    public function testExternalAppend() : void
    {
        $doc = Utility::mkdoc();
        $doc->html($html="<!DOCTYPE html>\n<html lang=\"pl\"><head><title>Test</title></head><body></body></html>\n");
        $doc->find('body')->appendWith('<p>Paragraph</p>');
        $this->assertEquals(
            str_replace('<body>','<body><p>Paragraph</p>',$html),
            $doc->saveHTML()
        );
    }

    public function testExternalAppendText(): void
    {
        $doc = Utility::mkdoc();
        $doc->setHtml("xxx");
        $doc->html($html="<!DOCTYPE html>\n<html lang=\"pl\"><head><title>Test</title></head><body></body></html>\n");
        $doc->find('body')->appendWith('Some text <p>Paragraph</p> more text');
        $this->assertEquals(
            str_replace('<body>','<body>Some text <p>Paragraph</p> more text',$html),
            $doc->saveHTML()
        );
    }
}