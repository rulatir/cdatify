<?php

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
            "shortcodes and text" => [html_entity_decode(
                <<<ENTITIES
&lt;p&gt;[icon]fh-dinner[/icon] wyżywienie zgodne z wybranym pakietem&lt;/p&gt;&lt;p&gt;[icon]fh-wifi[/icon] Wi-Fi Internet bezprzewodowy&lt;/p&gt;&lt;p&gt;[icon]fh-swimming-pool[/icon] Korzystanie z basenów i strefy wellness&lt;/p&gt;&lt;p&gt;[icon]fh-soccer-ball[/icon] Korzystanie z całej infrastruktury sportowej: Siłowni Fitness, Boiska Wielofunkcyjnego, Sali do Pilatesu i Jogi&lt;/p&gt;&lt;p&gt;[icon]fh-playground[/icon] Korzystanie z bogatej infrastruktury dla dzieci: place zabaw, sale zabaw&lt;/p&gt;
ENTITIES
            )],
            "avat-forcing shortcodes" => [
                '<p><span> whatevs </span><a href="mailto:[contact-email]">[contact-email]</a></p>'
            ]
        ];
    }

    /** @dataProvider convertAndBackData */
    public function testConvertAndBack(string $text) : void
    {
        $m = $this->getMockBuilder(ParameterTranslationDecider::class)->getMock();
        $m->expects(self::any())->method('shouldTranslateParameter')->willReturn(true);
        $m->expects(self::any())->method('shouldTranslateContent')->willReturn(true);
        $converter = new ShortcodeConverter(new RegularParser(),$m);
        $converted = $converter->sc2html($text);
        $unconverted = $converter->html2sc($converted);
        $this->assertEquals($text,$unconverted);
    }
}