<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NaturalLanguageTest extends TestCase
{
    public static function singleTokens() : array
    {
        return [
            "label tel:" => [true, "tel:"],
            "label ul." => [true, "ul."],
            "label godz." => [true, "godz."],
            "word" => [true, "Lomnica"],
            "YouTube id" => [false, "dQw4w9WgXcQ"],
            "number" => [false, "5"],
            "URL" => [false, "https://palac-lomnica.pl/oferta"],
        ];
    }

    #[DataProvider('singleTokens')]
    public function testIsTextContentNaturalLanguage(bool $expected, string $text) : void
    {
        self::assertSame($expected, isTextContentNaturalLanguage($text));
    }

    public function testLabelBesidePhoneLinkKeepsUnitForTranslation() : void
    {
        self::assertTrue(containsNaturalLanguage(
            '<p>tel: <a href="tel:+48757130460">+48 75 71 30 460</a></p>',
            '<p>tel: <a href="tel:+48757130460">+48 75 71 30 460</a></p>'
        ));
    }
}
