<?php

use PHPUnit\Framework\TestCase;

/**
 * Runs bin/cvt.php as a process, with stdin open and never written or closed,
 * so a command that reads stdin it does not need blocks and fails the timeout.
 */
class CliTest extends TestCase
{
    const TIMEOUT_SECONDS = 20;

    private string $dir;

    protected function setUp() : void
    {
        $this->dir = sys_get_temp_dir() . '/cvt-cli-' . bin2hex(random_bytes(4));
        mkdir("$this->dir/vocabulary", 0755, true);
    }

    protected function tearDown() : void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    /** @return array{0: ?int, 1: string} exit code (null on timeout) and stderr */
    private function cvt(array $args, array $env = []) : array
    {
        $cmd = array_merge([PHP_BINARY, __DIR__ . '/../bin/cvt.php'], $args);
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env + getenv());
        stream_set_blocking($pipes[2], false);
        $stderr = '';
        $deadline = microtime(true) + self::TIMEOUT_SECONDS;
        while (($status = proc_get_status($proc))['running']) {
            $stderr .= stream_get_contents($pipes[2]);
            if (microtime(true) > $deadline) {
                proc_terminate($proc);
                proc_close($proc);
                return [null, $stderr];
            }
            usleep(50000);
        }
        $stderr .= stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) fclose($pipe);
        proc_close($proc);
        return [$status['exitcode'], $stderr];
    }

    public function testCollectRunsWithoutReadingStdin() : void
    {
        file_put_contents("$this->dir/vocabulary/pl.yaml", "contact:\n  text: \"Kontakt\"\n");
        file_put_contents("$this->dir/vocabulary/en.yaml", "contact:\n  text: \"Contact\"\n");
        $zip = new ZipArchive();
        $zip->open("$this->dir/php.zip", ZipArchive::CREATE);
        $zip->addFromString('site-php-archive/locales/pl-PL/site-pl-PL.php', "<?php\nreturn array ('a.title' => 'Kontakt');\n");
        $zip->addFromString('site-php-archive/locales/en-GB/site-en-GB.php', "<?php\nreturn array ('a.title' => 'Socket');\n");
        $zip->close();

        [$exit, $stderr] = $this->cvt(['collect', "$this->dir/php.zip"], ['CVT_VOCABULARY_DIR' => "$this->dir/vocabulary"]);

        $this->assertNotNull($exit, "collect did not finish within " . self::TIMEOUT_SECONDS . " s; stderr: $stderr");
        $this->assertSame(0, $exit, $stderr);
        $this->assertSame(['a.title' => 'Contact'], require "$this->dir/site-en-GB.php");
    }
}
