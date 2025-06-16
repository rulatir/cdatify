<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use QueryPath\QueryPath;
use Rulatir\Cdatify\QueryPath\MapTo;
use Rulatir\Cdatify\Shortcode\Contracts\ParameterTranslationDecider;
use Rulatir\Cdatify\Shortcode\ParameterTranslationDeciderAnswerFile;
use Rulatir\Cdatify\Shortcode\ShortcodeConverter;
use Thunder\Shortcode\Parser\RegularParser;

class ShortcodeConverterTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        ini_set('memory_limit','4G');
        QueryPath::enable(MapTo::class);
    }

    public static function convertAndBackData() : array
    {
        return [
            "shortcodes and text" => [
                    "<p>[icon]fh-dinner[/icon] wyżywienie zgodne z wybranym pakietem</p>" .
                    "<p>[icon]fh-wifi[/icon] Wi-Fi Internet bezprzewodowy</p>" .
                    "<p>[icon]fh-swimming-pool[/icon] Korzystanie z basenów i strefy wellness</p>" .
                    "<p>[icon]fh-soccer-ball[/icon] Korzystanie z całej infrastruktury sportowej: " .
                    "Siłowni Fitness, Boiska Wielofunkcyjnego, Sali do Pilatesu i Jogi</p>" .
                    "<p>[icon]fh-playground[/icon] Korzystanie z bogatej infrastruktury dla dzieci: " .
                    "place zabaw, sale zabaw</p>"
                ,false   ],
            "avat-forcing shortcodes" => [
                '<p><span> whatevs </span><a href="mailto:[contact-email]">[contact-email]</a></p>',true
            ],
            "worse case" => [
                "<p>[icon]fh-dinner[/icon] wyżywienie [sorry]<a href=\"mailto:[contact-email]\">zgodne z wybranym pakietem</a>[/sorry]</p>" .
                "<p>[icon]fh-wifi[/icon] Wi-Fi Internet bezprzewodowy</p>" .
                "<p>[icon]fh-swimming-pool[/icon] Korzystanie z basenów i strefy wellness</p>" .
                "<p>[icon]fh-soccer-ball[/icon] Korzystanie z całej infrastruktury sportowej: " .
                "Siłowni Fitness, Boiska Wielofunkcyjnego, Sali do Pilatesu i Jogi</p>" .
                "<p>[icon]fh-playground[/icon] Korzystanie z bogatej infrastruktury dla dzieci: " .
                "place zabaw, sale zabaw</p>",
                true
            ]
        ];
    }

    #[DataProvider('convertAndBackData')]
    public function testConvertAndBack(string $text, bool $shouldContainAvat) : void
    {
        $m = $this->getMockBuilder(ParameterTranslationDecider::class)->getMock();
        $m->expects(self::any())->method('shouldTranslateParameter')->willReturn(true);
        $m->expects(self::any())->method('shouldTranslateContent')->willReturn(true);
        $converter = new ShortcodeConverter(new RegularParser(),$m);
        $converted = $converter->sc2html($text);
        self::assertStringContainsString('<sc-t>',$converted);
        self::assertEquals(
            $shouldContainAvat,
            str_contains($converted,'<ft-t>'),
            "Converted string should contain AVAT tags if and only if necessary"
        );
        $unconverted = $converter->html2sc($converted);
        self::assertEquals($text,$unconverted);
    }
}