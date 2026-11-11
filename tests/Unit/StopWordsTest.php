<?php

declare(strict_types=1);

namespace IndianOpinion\Simhash\Tests\Unit;

use IndianOpinion\Simhash\Config;
use IndianOpinion\Simhash\Exception\ConfigException;
use IndianOpinion\Simhash\Simhash;
use IndianOpinion\Simhash\StopWords;
use IndianOpinion\Simhash\Tests\Support\TestCase;

/**
 * T25 (B26), T26 (B27).
 */
final class StopWordsTest extends TestCase
{
    public function testOffByDefault(): void
    {
        self::assertSame(['the', 'monsoon', 'is', 'here'], self::tokens('The monsoon is here'));
    }

    public function testListsDropTokensAfterFolding(): void
    {
        $stop = StopWords::fromArray(['en' => ['THE', 'Is'], 'hi' => ['में', 'की']]);
        $config = new Config(stopWords: $stop);
        self::assertSame(['monsoon', 'here'], self::tokens('The monsoon is here', $config));
        self::assertSame(['भारत', 'मानसून'], self::tokens('भारत में मानसून', $config));
        // Exact matching, no stemming.
        self::assertSame(['these', 'monsoon'], self::tokens('These monsoon', $config));
    }

    public function testStopWordsFromFile(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'stop');
        self::assertIsString($file);
        file_put_contents($file, "# English\nthe\n\n  a  \nlive updates\n");
        try {
            $stop = StopWords::fromFile('en', $file);
            self::assertSame(['en' => ['a', 'live updates', 'the']], $stop->lists());
        } finally {
            unlink($file);
        }
    }

    public function testBadLanguageCodeRejected(): void
    {
        $this->expectException(ConfigException::class);
        StopWords::fromArray(['english language' => ['the']]);
    }

    public function testMissingFileRejected(): void
    {
        $this->expectException(ConfigException::class);
        StopWords::fromFile('en', '/nonexistent/stop.txt');
    }

    public function testAllStopWordTitleIsNotEmpty(): void
    {
        $s = new Simhash(new Config(stopWords: StopWords::fromArray(['en' => ['the', 'who']])));
        $fp = $s->hash('The Who');
        self::assertFalse($fp->isEmpty());
        self::assertTrue($fp->stopWordsSkipped());
        self::assertSame(['the', 'who'], $s->tokens('The Who'));
        self::assertContains('stop_words_skipped', $fp->flags());
    }

    public function testStopWordsChangeTheConfigId(): void
    {
        $a = new Config();
        $b = new Config(stopWords: StopWords::fromArray(['en' => ['the']]));
        $c = new Config(stopWords: StopWords::fromArray(['en' => ['a']]));
        self::assertNotSame($a->id(), $b->id());
        self::assertNotSame($b->id(), $c->id());
    }
}
