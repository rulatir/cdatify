<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NormalizePipesTest extends TestCase
{
    public static function normalizePipesData() : array
    {
        return [
            "empty" => ["", ""],
            "ws" => ["  ", "  "],
            "plain" => ["foo", "foo"],
            "surrounded by whitespace" => ["  foo ","  foo "],
            "containing whitespace" => ["foo  bar","foo  bar"],
            "containing pipe" => ["  foo bar ","  foo|bar "],
            "containing spaced pipes" => ["  foo bar ban baz  next gen   ","  foo|bar |ban|  baz  next   || gen   "]
        ];
    }

    #[DataProvider('normalizePipesData')]
    public function testNormalizePipes(string $expected, string $input) : void
    {
        self::assertEquals($expected, normalizePipes($input));
    }
}