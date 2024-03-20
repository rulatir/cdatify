<?php

use Masterminds\HTML5;
use PHPUnit\Framework\TestCase;
use QueryPath\QueryPath;
use Rulatir\Cdatify\Utility;

class QueryPathSuitability extends TestCase
{
    public function testMakeText(): void
    {
        self::assertTrue((QQ('whatever'))->get(0) instanceof DOMText );
    }

    public function testMakeFragment(): void
    {
        $section = QQ('<section></section>');
        $frag = QQ($fragHTML='whatever <p>Look, a papappapp!</p> forever');
        $section->append($frag);
        self::assertEquals("<section>$fragHTML</section>",$section->html());
    }

    public function testObeyWhenISayMakeIllegalFragment() : void
    {
        $html = '<td>shame <li>shame</li> shame</td>';
        $dom = QQ($html);
        self::assertInstanceOf(DOMElement::class, $dom->get(0));
        self::assertInstanceOf(DOMText::class, $dom->eq(0)->contents()->get(0));
        self::assertInstanceOf(DOMElement::class, $dom->eq(0)->contents()->get(1));
        self::assertInstanceOf(DOMText::class, $dom->eq(0)->contents()->get(2));
        self::assertEquals($html,$dom->html());
    }
    public function testExternalAppendText(): void
    {
        $section = QQ('<section></section>');
        $section->append(QQ('whatever'));
        self::assertEquals('<section>whatever</section>',$section->html());
        self::assertEquals('whatever',$section->innerHTML5());
    }

    public function testExternalAppendWholeTextToDocument(): void
    {
        $html= /** @lang HTML */
            <<<HTML
<!DOCTYPE html>
<html lang="en">
    <head>
        <title>Processing container</title>
        <meta charset="UTF-8">
    </head>
    <body>
        <section id="processing-container"></section>    
    </body>
</html>
HTML;
        $doc = html5qp($html);
        $doc->find('#processing-container')->append(QQ($addition='whatever <p>Papappapp!</p> whatever'));
        $contents = $doc->find('#processing-container')->contents();
        self::assertTrue($contents->get(0) instanceof DOMText);
        self::assertTrue($contents->get(1)->tagName === 'p');
        self::assertTrue($contents->get(2) instanceof DOMText);
        $expected = str_replace(
            '<section id="processing-container">',
            '<section id="processing-container">'.$addition,
            $html
        );
        $doc2=html5qp($expected);
        $expected = trim((new HTML5())->saveHTML($doc2->document()));
        self::assertEquals($expected,trim((new HTML5())->saveHTML($doc->document())));
    }
}