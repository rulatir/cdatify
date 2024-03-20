<?php

namespace Rulatir\Cdatify;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NormalizeStarsTest extends TestCase
{
    public static function starsData() : array
    {
        return [
            'already good' => ['some <md-star>emphasized</md-star> word','some *emphasized* word'],
            'defragment' => ['<md-star>Najlepsze</md-star> hotele','*Naj*lepsze hotele'],
            'one star' => ['ile to będzie cztery*pięć, powiedz proszę','ile to będzie cztery*pięć, powiedz proszę'],
            'in tag' => ['Nasze <span><md-star>Najlepsze</md-star></span> hotele','Nasze <span>*Naj*lepsze</span> hotele'],
            'spaced out' => ['we * all * like stars','we * all * like stars'],
            'with ogonek' => ['<md-star>Największy</md-star> basen','*Naj*większy basen']
        ];
    }

    #[DataProvider('starsData')]
    public function testNormalizeStars(string $expected, string $input) : void
    {
        self::assertEquals($expected, normalizeStars($input));
    }
}