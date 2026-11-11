<?php

declare(strict_types=1);

namespace IndianOpinion\Simhash\Tests\Unit;

use IndianOpinion\Simhash\Simhash;
use IndianOpinion\Simhash\Tests\Support\Corpus;
use IndianOpinion\Simhash\Tests\Support\TestCase;

/**
 * T57 (section 8, adversarial input) and T58 (section 9, loose performance
 * smoke). Bounds are deliberately loose: CI machines vary.
 */
final class RobustnessTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string}>
     */
    public static function hostile(): iterable
    {
        yield 'marks' => [str_repeat("\u{093E}", 1400)];
        yield 'virama chain' => [str_repeat("\u{0915}\u{094D}", 700)];
        yield 'joiners' => [str_repeat("\u{0915}\u{200D}\u{200C}", 500)];
        yield 'digits' => [str_repeat('9', 4096)];
        yield 'www' => [str_repeat('www.', 1024)];
        yield 'at signs' => [str_repeat('a@b.', 1024)];
        yield 'emoji zwj' => [str_repeat("\u{1F468}\u{200D}", 580)];
        yield 'mixed scripts' => [str_repeat("a\u{0915}\u{0B95}\u{4E00}", 400)];
        yield 'flags' => [str_repeat("\u{1F1EE}", 1000)];
        yield 'apostrophes' => [str_repeat("a'", 2048)];
    }

    /**
     * @dataProvider hostile
     */
    public function testHostileInputFinishesInBudget(string $text): void
    {
        $s = new Simhash();
        $start = hrtime(true);
        $s->hash($text);
        $s->hash($text . ' tail');
        $ms = (hrtime(true) - $start) / 1e6;
        self::assertLessThan(1500, $ms, 'two hashes of hostile input took ' . round($ms) . ' ms');
    }

    public function testPerformanceSmoke(): void
    {
        $s = new Simhash();
        $titles = array_column(Corpus::titles(), 'text');
        $start = hrtime(true);
        foreach ($titles as $t) {
            $s->hash($t);
        }
        $perTitleUs = (hrtime(true) - $start) / 1000 / count($titles);
        self::assertLessThan(5000, $perTitleUs, 'mean per title ' . round($perTitleUs) . ' us');
    }

    public function testBenchmarkScriptRuns(): void
    {
        $proc = proc_open(
            [PHP_BINARY, __DIR__ . '/../../bin/benchmark.php', '--quick'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($proc);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($proc), $err);
        self::assertStringContainsString('hash 80-char English title', $out);
        self::assertStringContainsString('findNearPairs', $out);
    }
}
