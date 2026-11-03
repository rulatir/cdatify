<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/helpers.php';

class ApplyTermsTest extends TestCase
{
    const CATALOGS = [
        'pl' => [
            'nav.contact' => ['text' => 'Kontakt', 'wrong' => []],
            'booking.check-dates' => ['text' => 'Sprawdź terminy', 'wrong' => []],
            'only.pl' => ['text' => 'Tylko po polsku', 'wrong' => []],
        ],
        'en' => [
            'nav.contact' => ['text' => 'Contact', 'wrong' => ['Socket']],
            'booking.check-dates' => ['text' => 'Check availability', 'wrong' => ['Check deadlines']],
        ],
    ];

    private string $problems;

    protected function setUp() : void
    {
        $this->problems = sys_get_temp_dir() . '/apply-terms-' . bin2hex(random_bytes(4)) . '.json';
    }

    protected function tearDown() : void
    {
        @unlink($this->problems);
    }

    private function run_(array $target, array $source) : array
    {
        return applyTerms($target, 'en', $source, 'pl', self::CATALOGS, $this->problems);
    }

    public function testWholeTextMatchIsReplacedIgnoringCaseAndSpace() : void
    {
        $result = $this->run_(
            ['a.title' => 'Socket', 'b.label' => 'CHECK DEADLINES'],
            ['a.title' => 'Kontakt', 'b.label' => '  sprawdź TERMINY ']
        );
        $this->assertSame(['a.title' => 'Contact', 'b.label' => 'Check availability'], $result);
    }

    public function testSlugUnitsAreLeftToFixSlugs() : void
    {
        $result = $this->run_(['a.slug' => 'socket'], ['a.slug' => 'Kontakt']);
        $this->assertSame('socket', $result['a.slug']);
    }

    public function testTermWithoutTargetEntryIsNotApplied() : void
    {
        $result = $this->run_(['a.title' => 'Only in Polish'], ['a.title' => 'Tylko po polsku']);
        $this->assertSame('Only in Polish', $result['a.title']);
    }

    public function testLongerTextIsKeptAndKnownWrongRenderingReported() : void
    {
        $source = ['a.text' => 'Sprawdź terminy w naszym kalendarzu.', 'b.text' => 'Napisz do nas: kontakt poniżej.'];
        $target = ['a.text' => 'Check deadlines in our calendar.', 'b.text' => 'Write to us: contact below.'];
        $this->assertSame($target, $this->run_($target, $source));
        $problems = json_decode(file_get_contents($this->problems), true);
        $this->assertSame(['a.text'], array_keys($problems));
        $this->assertSame('booking.check-dates', $problems['a.text'][0]['term']);
        $this->assertSame('Check deadlines', $problems['a.text'][0]['wrong']);
    }

    public function testNoProblemsWritesNoFile() : void
    {
        $this->run_(['a.title' => 'Contact'], ['a.title' => 'Kontakt']);
        $this->assertFileDoesNotExist($this->problems);
    }

    public function testCollectWritesCorrectedLocalesNextToTheArchive() : void
    {
        if (!class_exists(\Symfony\Component\Yaml\Yaml::class)) {
            $this->markTestSkipped('symfony/yaml is not installed');
        }
        $dir = sys_get_temp_dir() . '/collect-' . bin2hex(random_bytes(4));
        mkdir("$dir/vocabulary", 0755, true);
        file_put_contents("$dir/vocabulary/pl.yaml", "nav.contact:\n  text: \"Kontakt\"\n");
        file_put_contents("$dir/vocabulary/en.yaml", "nav.contact:\n  text: \"Contact\"\n  wrong:\n    - \"Socket\"\n");
        $base = 'site-php-archive/locales';
        $zip = new ZipArchive();
        $zip->open("$dir/php.zip", ZipArchive::CREATE);
        $zip->addFromString('site-php-archive/README.txt', 'readme');
        $zip->addFromString("$base/pl-PL/site-pl-PL.php", "<?php\nreturn array ('a.title' => 'Kontakt', 'a.slug' => 'kontakt');\n");
        $zip->addFromString("$base/en-GB/site-en-GB.php", "<?php\nreturn array ('a.title' => 'Socket', 'a.slug' => 'socket');\n");
        $zip->addFromString("$base/de-DE/site-de-DE.php", "<?php\nreturn array ('a.title' => 'Steckdose', 'a.slug' => 'steckdose');\n");
        $zip->close();
        putenv(VOCABULARY_DIR_ENV_VAR . "=$dir/vocabulary");
        try {
            $zipBefore = file_get_contents("$dir/php.zip");
            cmd_collect("$dir/php.zip", 'pl', [], $dir, collectProblemsDir("$dir/php.zip"));
            $en = require "$dir/site-en-GB.php";
            $de = require "$dir/site-de-DE.php";
            $plWritten = file_exists("$dir/site-pl-PL.php");
            $zipUnchanged = $zipBefore === file_get_contents("$dir/php.zip");
            $fuzzy = json_decode(file_get_contents("$dir/php.problems/fuzzy.json"), true);
        } finally {
            putenv(VOCABULARY_DIR_ENV_VAR);
            exec('rm -rf ' . escapeshellarg($dir));
        }
        $this->assertSame(['a.title' => 'Contact', 'a.slug' => 'contact'], $en);
        $this->assertFalse($plWritten);
        $this->assertTrue($zipUnchanged);
        $this->assertSame('Steckdose', $de['a.title']);
        $this->assertSame(['de-DE' => [['asset' => 'a.title', 'term' => 'nav.contact', 'source' => 'Kontakt', 'target' => 'Steckdose']]], $fuzzy);
    }
}
