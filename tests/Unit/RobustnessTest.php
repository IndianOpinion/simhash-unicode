<?php

declare(strict_types=1);

namespace IndianOpinion\Simhash\Tests\Unit;

use IndianOpinion\Simhash\Config;
use IndianOpinion\Simhash\Simhash;
use IndianOpinion\Simhash\TextNormalizer;
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

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function hostileLarge(): iterable
    {
        yield 'one huge mark cluster over the cap' => [str_repeat("\u{0301}", 360000)];
        yield 'hyphen chain' => [str_repeat('a-', 150000)];
        yield 'underscore chain' => [str_repeat('a_', 150000)];
        yield 'invalid bytes, scrubbed' => [str_repeat("\xFF", 300000)];
    }

    /**
     * Inputs near the largest allowed cap must stay linear: a quadratic step
     * here took minutes before.
     *
     * @dataProvider hostileLarge
     */
    public function testHostileInputAtLargeCapFinishesInBudget(string $text): void
    {
        $s = new Simhash(new Config(maxInputBytes: 1048576, scrub: true));
        $start = hrtime(true);
        $s->hash($text);
        $ms = (hrtime(true) - $start) / 1e6;
        self::assertLessThan(5000, $ms, 'hash of large hostile input took ' . round($ms) . ' ms');
    }

    public function testTruncateWalkIsBounded(): void
    {
        // Within the walk limit: the whole cluster "b" plus marks is dropped.
        self::assertSame('a', TextNormalizer::truncate('ab' . str_repeat("\u{0301}", 10), 10));
        // Beyond it: cut at the code point boundary at or before the limit.
        $head = TextNormalizer::truncate('ab' . str_repeat("\u{0301}", 300), 200);
        self::assertSame(1, preg_match('//u', $head));
        self::assertSame(200, strlen($head));
    }

    public function testBadOffsetDoesNotDependOnPcreJit(): void
    {
        $old = ini_get('pcre.jit');
        ini_set('pcre.jit', '0');
        try {
            $text = str_repeat("\u{0905}", 400000) . "\xFF";
            self::assertSame(1200000, TextNormalizer::firstBadOffset($text));
            $ascii = str_repeat('a', 300000);
            self::assertSame($ascii . "\u{FFFD}", TextNormalizer::scrub($ascii . "\xFF"));
        } finally {
            ini_set('pcre.jit', $old === false ? '1' : $old);
        }
    }
}
