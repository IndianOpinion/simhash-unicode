<?php

declare(strict_types=1);

namespace IndianOpinion\Simhash\Tests\Unit;

use IndianOpinion\Simhash\Simhash;
use IndianOpinion\Simhash\Tests\Support\TestCase;

/**
 * T60: requiring bootstrap.php alone, in a fresh PHP process with no
 * Composer autoloader, hashes a title.
 */
final class BootstrapTest extends TestCase
{
    public function testBootstrapWithoutComposer(): void
    {
        $code = 'require ' . var_export(dirname(__DIR__, 2) . '/bootstrap.php', true) . ';'
            . 'echo (new IndianOpinion\Simhash\Simhash())->hash("भारत में मानसून")->hex();'
            . 'echo " ", class_exists("Composer\\\\Autoload\\\\ClassLoader", false) ? "composer" : "standalone";';
        $proc = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($proc);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($proc);
        self::assertSame(0, $status, $err);
        self::assertSame((new Simhash())->hash('भारत में मानसून')->hex() . ' standalone', $out);
    }
}
