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
