<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/helpers.php';

class FixSlugsTest extends TestCase
{
    private function run_(array $target, array $source, ?string $problems = null) : array
    {
        $php = cmd_fix_slugs($target, 'cs', $source, 'pl', [], $problems);
        return eval(substr($php, strlen('<?php')));
    }

    public function testMissingAndEmptyTranslationsAreIgnored() : void
    {
        $source = [
            'a.title' => 'Regulamin',
            'a.slug' => 'regulamin',
            'b.title' => 'Regulamin',
            'c.title' => 'Regulamin',
        ];
        $target = [
            'a.title' => 'nařízení',
            'a.slug' => 'režim',
            'b.title' => '',
        ];
        $this->assertSame('narizeni', $this->run_($target, $source)['a.slug']);
    }

    public function testOwnTitleWinsOverOtherTranslations() : void
    {
        $source = ['a.title' => 'Regulamin', 'a.slug' => 'regulamin', 'b.title' => 'Regulamin'];
        $target = ['a.title' => 'nařízení', 'a.slug' => 'režim', 'b.title' => 'pravidla'];
        $this->assertSame('narizeni', $this->run_($target, $source)['a.slug']);
    }

    public function testOwnTitleKeepsHashPrefix() : void
    {
        $source = ['a.title' => 'Regulamin', 'a.slug' => '#regulamin'];
        $target = ['a.title' => 'nařízení', 'a.slug' => '#režim'];
        $this->assertSame('#narizeni', $this->run_($target, $source)['a.slug']);
    }

    public function testOtherTitlesAreUsedWhenOwnTitleDoesNotSlugifyToTheSlug() : void
    {
        $source = ['a.title' => 'Zasady', 'a.slug' => 'regulamin', 'b.title' => 'Regulamin'];
        $target = ['a.title' => 'zásady', 'a.slug' => 'režim', 'b.title' => 'nařízení'];
        $this->assertSame('narizeni', $this->run_($target, $source)['a.slug']);
    }

    public function testAmbiguousNonEmptyTranslationsAreReported() : void
    {
        $source = ['a.title' => 'Zasady', 'a.slug' => 'regulamin', 'b.title' => 'Regulamin', 'c.title' => 'Regulamin'];
        $target = ['a.title' => 'zásady', 'a.slug' => 'režim', 'b.title' => 'nařízení', 'c.title' => 'pravidla'];
        $problems = tempnam(sys_get_temp_dir(), 'fs');
        $this->assertSame('režim', $this->run_($target, $source, $problems)['a.slug']);
        $report = json_decode(file_get_contents($problems), true);
        unlink($problems);
        $this->assertSame('ambiguous_translations', $report[0]['reason']);
        $this->assertSame([
            'b.title' => ['source' => 'Regulamin', 'target' => 'nařízení', 'slug' => 'narizeni', 'kind' => 'text'],
            'c.title' => ['source' => 'Regulamin', 'target' => 'pravidla', 'slug' => 'pravidla', 'kind' => 'text'],
        ], $report[0]['candidates']);
    }

    public function testTextTranslationDecidesAndOverwritesAWrongSlugSource() : void
    {
        $source = [
            's.title' => 'Przyjęcia okolicznościowe', 's.slug' => 'oferta-okolicznosciowa',
            'l.link' => 'oferta-okolicznosciowa', 'l.link_label' => 'Oferta okolicznościowa',
        ];
        $target = [
            's.title' => 'Příležitostné večírky', 's.slug' => 'prilezitostna-nabidka',
            'l.link' => 'Nabídka-příležitostná', 'l.link_label' => 'Příležitostná nabídka',
        ];
        $result = $this->run_($target, $source);
        $this->assertSame('prilezitostna-nabidka', $result['s.slug']);
        $this->assertSame('prilezitostna-nabidka', $result['l.link']);
    }

    public function testSlugSourceFollowsTheSlugDecidedByItsOwnTitle() : void
    {
        $source = ['a.title' => 'Regulamin', 'a.slug' => '#regulamin', 'l.link' => '#regulamin'];
        $target = ['a.title' => 'nařízení', 'a.slug' => '#x', 'l.link' => '#Řád'];
        $result = $this->run_($target, $source);
        $this->assertSame('#narizeni', $result['a.slug']);
        $this->assertSame('#narizeni', $result['l.link']);
    }

    public function testSourceLanguageProperNamesSlugifyInTheTarget() : void
    {
        $source = ['a.title' => 'Restauracja Pałacowa', 'a.slug' => 'restauracja-palacowa'];
        $target = ['a.title' => 'Pałacowa restaurace', 'a.slug' => 'x'];
        $this->assertSame('palacowa-restaurace', $this->run_($target, $source)['a.slug']);
    }

    public function testSlugSourcesDecideWhenNoTextIsTranslated() : void
    {
        $source = ['a.slug' => 'regulamin', 'b.title' => 'Regulamin', 'l.link' => 'regulamin'];
        $target = ['a.slug' => 'x', 'l.link' => 'Nařízení'];
        $this->assertSame('narizeni', $this->run_($target, $source)['a.slug']);
    }

    public function testOwnTitleMatchesASlugWithoutItsShortWords() : void
    {
        $source = ['a.title' => 'Przyjęcia i wesela', 'a.slug' => 'przyjecia-wesela'];
        $target = ['a.title' => 'Recepce a svatby', 'a.slug' => 'svatebni-hostiny'];
        $this->assertSame('recepce-a-svatby', $this->run_($target, $source)['a.slug']);
    }

    public function testOtherTitlesMatchASlugWithoutItsShortWords() : void
    {
        $source = ['a.title' => 'Wesela', 'a.slug' => 'pokoje-z-widokiem', 'b.title' => 'Pokoje widokiem'];
        $target = ['a.title' => 'svatby', 'a.slug' => 'x', 'b.title' => 'Pokoje s výhledem'];
        $this->assertSame('pokoje-s-vyhledem', $this->run_($target, $source)['a.slug']);
    }

    public function testShortWordFallbackKeepsDigits() : void
    {
        $source = ['a.title' => 'Pokój', 'a.slug' => 'pokoj-2'];
        $target = ['a.title' => 'Pokoj', 'a.slug' => 'untouched'];
        $this->assertSame('untouched', $this->run_($target, $source)['a.slug']);
    }

    public function testHomePageGetsTheCmsLanguageCode() : void
    {
        $source = ['h.title' => 'Home', 'h.slug' => 'index'];
        $target = ['h.title' => 'Domů', 'h.slug' => 'index'];
        $this->assertSame('cz', $this->run_($target, $source)['h.slug']);
        $this->assertSame('de', cmsLanguageCode('de-DE'));
        $this->assertSame('en', cmsLanguageCode('en-GB'));
        $this->assertSame('cz', cmsLanguageCode('cs-CZ'));
    }

    public function testNoTranslationAtAllIsReported() : void
    {
        $source = ['a.title' => 'Regulamin', 'a.slug' => 'regulamin'];
        $target = ['a.slug' => 'režim'];
        $problems = tempnam(sys_get_temp_dir(), 'fs');
        $this->assertSame('režim', $this->run_($target, $source, $problems)['a.slug']);
        $report = json_decode(file_get_contents($problems), true);
        unlink($problems);
        $this->assertSame('missing_translation', $report[0]['reason']);
    }
}
