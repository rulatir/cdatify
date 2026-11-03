<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/helpers.php';

class DeslugifyTest extends TestCase
{
    public static function setUpBeforeClass() : void
    {
        \QueryPath\QueryPath::enable(\Rulatir\Cdatify\QueryPath\MapTo::class);
    }

    private function deslugify_(string $slug, ?string $ownTitle, array $texts) : array
    {
        $slugifier = makeSlugifier('pl');
        return deslugify($slug, $ownTitle, slugTextIndex($texts, $slugifier, 'pl'), $slugifier, 'pl');
    }

    public function testOwnTitleComesFirst() : void
    {
        $r = $this->deslugify_('pokoje', 'Pokoje', ['b.title' => 'POKOJE']);
        $this->assertSame(['prefix' => '', 'text' => 'Pokoje', 'unresolved' => []], $r);
    }

    public function testWholeTextOfAnotherUnitSuppliesPunctuation() : void
    {
        $r = $this->deslugify_('jeden-dzien-wiele-odkryc', 'Kontakt', ['b.title' => 'Jeden dzień, wiele odkryć']);
        $this->assertSame('Jeden dzień, wiele odkryć', $r['text']);
    }

    public function testDifferingWholeTextsFallBackToWords() : void
    {
        $r = $this->deslugify_('pokoje', null, ['b.title' => 'Pokoje', 'c.title' => 'POKOJE']);
        $this->assertSame('pokoje', $r['text']);
        $this->assertSame([], $r['unresolved']);
    }

    public function testWordsGetAccentsFromTheSiteText() : void
    {
        $r = $this->deslugify_('napoje-gorace', 'Napoje', ['b.text' => '<p>Gorące napoje i <b>zimne</b> desery</p>']);
        $this->assertSame(['prefix' => '', 'text' => 'napoje gorące', 'unresolved' => []], $r);
    }

    public function testUnaccentedWordUsedByTheSiteStaysUnaccented() : void
    {
        $r = $this->deslugify_('mezoterapia-twarzy', null, ['b.text' => 'Mezoterapia twarzy i ciała']);
        $this->assertSame('mezoterapia twarzy', $r['text']);
    }

    public function testUnknownAndAmbiguousWordsAreReported() : void
    {
        $r = $this->deslugify_('laka-zrodla-2025', null, ['b.text' => 'Łąka i łaka', 'c.text' => 'Inne']);
        $this->assertSame('laka zrodla 2025', $r['text']);
        $this->assertSame(['laka' => ['łąka', 'łaka'], 'zrodla' => []], $r['unresolved']);
    }

    public function testOwnTitleMatchesASlugWithoutItsShortWords() : void
    {
        $r = $this->deslugify_('przyjecia-wesela', 'Przyjęcia i wesela', []);
        $this->assertSame('Przyjęcia i wesela', $r['text']);
    }

    public function testWholeTextMatchesASlugWithoutItsShortWords() : void
    {
        $r = $this->deslugify_('pokoje-widokiem', 'Kontakt', ['b.title' => 'Pokoje z widokiem']);
        $this->assertSame('Pokoje z widokiem', $r['text']);
    }

    public function testStrongSlugDropsOnlyShortWords() : void
    {
        $this->assertSame('przyjecia-wesela', strongSlug('przyjecia-i-wesela'));
        $this->assertSame('pokoj-2', strongSlug('pokoj-2'));
        $this->assertSame('', strongSlug('i-w-z'));
    }

    public function testHashPrefixIsReturnedSeparately() : void
    {
        $r = $this->deslugify_('#pokoje', 'Pokoje', []);
        $this->assertSame(['prefix' => '#', 'text' => 'Pokoje', 'unresolved' => []], $r);
    }

    /**
     * html, then a stand-in for AWS Translate that replaces the Polish values, then xlf.
     * @return array<string,string> resname => target
     */
    private function roundTrip(array $units, string $targetLanguage, array $translations, bool $merge) : array
    {
        $xlf = renderTemplate(...[...sourceXlfTemplate([
            '%%%original%%%' => 'test',
            '%%%source-language%%%' => 'pl-PL',
            '%%%target-language%%%' => $targetLanguage,
        ]), array_map(fn($u) => [...$u, 'source' => htmlspecialchars($u['source'])], $units)]);
        $translated = str_replace(array_keys($translations), array_values($translations), cmd_html($xlf, false, $merge));
        preg_match_all('~<trans-unit id="[^"]*" resname="([^"]*)"[^>]*>\s*<source/>\s*<target>(.*?)</target>~s',
            cmd_xlf($translated), $m, PREG_SET_ORDER);
        $targets = [];
        foreach ($m as [, $resname, $target]) $targets[$resname] = htmlspecialchars_decode($target);
        return $targets;
    }

    public static function germanSlugs() : array
    {
        return [
            'capital umlaut' => ['Über Uns', 'ueber-uns'],
            'umlaut' => ['Schöne Zimmer', 'schoene-zimmer'],
            'sharp s' => ['Große Straße', 'grosse-strasse'],
            'capitals' => ['ÄRGER Öl', 'aerger-oel'],
            'dash and umlaut' => ['Alter Stall – Füße', 'alter-stall-fuesse'],
        ];
    }

    /** Our convention for German slugs: lowercase, umlauts and ß as digraphs. */
    #[\PHPUnit\Framework\Attributes\DataProvider('germanSlugs')]
    public function testGermanSlugsAreLowercaseWithDigraphs(string $text, string $slug) : void
    {
        $this->assertSame($slug, applySlugDirective($text, '', 'de-DE'));
    }

    public function testXlfSlugifiesTranslatedSlugTextsAndRestoresThePrefix() : void
    {
        $units = [
            ['id' => '1', 'resname' => 'pages.a.title', 'source' => 'Jeden dzień, wiele odkryć'],
            ['id' => '2', 'resname' => 'sections.b.slug', 'source' => '#jeden-dzien-wiele-odkryc'],
            ['id' => '3', 'resname' => 'pages.c.title', 'source' => 'Piękne pokoje'],
            ['id' => '4', 'resname' => 'pages.c.slug', 'source' => 'piekne-pokoje'],
        ];
        $translations = ['Jeden dzień, wiele odkryć' => 'Ein Tag, viele Entdeckungen', 'Piękne pokoje' => 'Schöne Zimmer'];
        foreach ([false, true] as $merge) {
            $this->assertSame([
                'pages.a.title' => 'Ein Tag, viele Entdeckungen',
                'sections.b.slug' => '#ein-tag-viele-entdeckungen',
                'pages.c.title' => 'Schöne Zimmer',
                'pages.c.slug' => 'schoene-zimmer',
            ], $this->roundTrip($units, 'de-DE', $translations, $merge), $merge ? 'merged' : 'not merged');
        }
    }

    public function testHtmlPutsRestoredSlugIntoTheDuplicateSetOfItsWholeText() : void
    {
        $units = [
            ['id' => '1', 'resname' => 'pages.a.title', 'source' => 'Jeden dzień, wiele odkryć'],
            ['id' => '2', 'resname' => 'sections.b.title', 'source' => 'Kontakt'],
            ['id' => '3', 'resname' => 'sections.b.slug', 'source' => '#jeden-dzien-wiele-odkryc'],
        ];
        $xlf = renderTemplate(...[...sourceXlfTemplate([
            '%%%original%%%' => 'test',
            '%%%source-language%%%' => 'pl-PL',
            '%%%target-language%%%' => 'en-GB',
        ]), array_map(fn($u) => [...$u, 'source' => htmlspecialchars($u['source'])], $units)]);

        $doc = html5qp(cmd_html($xlf, false, true));

        $sets = $doc->find('section.duplicate-set');
        $this->assertSame(2, $sets->count());
        $set = $sets->filterCallback(fn($i, $e) => str_contains($e->textContent, 'wiele odkryć'));
        $resnames = [];
        foreach ($set->find('ul.ids > li')->get() as $li) $resnames[] = $li->getAttribute('data-resname');
        $this->assertSame(['pages.a.title', 'sections.b.slug'], $resnames);
        $slugLi = $set->find('li[data-resname="sections.b.slug"]');
        $this->assertSame('slugify', $slugLi->attr('data-transform'));
        $this->assertSame('#', $slugLi->attr('data-prefix'));
        $this->assertFalse($set->find('li[data-resname="pages.a.title"]')->get(0)->hasAttribute('data-transform'));
    }
}
