<?php

declare(strict_types=1);

namespace IndianOpinion\Simhash\Tests\Unit;

use IndianOpinion\Simhash\Config;
use IndianOpinion\Simhash\Simhash;
use IndianOpinion\Simhash\Tests\Support\Corpus;
use IndianOpinion\Simhash\Tests\Support\TestCase;
use Normalizer;

/**
 * T22 (B22, B25), T23 (B23, B25), T24 (B24).
 *
 * B25: each option has a pair of titles that differ only in that aspect,
 * with the distance shown with the option on and off.
 */
final class NormalisationTest extends TestCase
{
    public function testFullwidthAndLigaturesEqualUnderNfkc(): void
    {
        $plain = 'Fullwidth title from a feed';
        $wide = Corpus::text('sp-12');
        self::assertSame(0, self::distance($plain, $wide));
        $none = new Config(form: Config::FORM_NONE);
        self::assertGreaterThan(6, self::distance($plain, $wide, $none));

        $lig = "e\u{FB03}cient o\u{FB00}ice \u{FB01}les";
        self::assertSame(['efficient', 'office', 'files'], self::tokens($lig));
        self::assertSame(0, self::distance('efficient office files', $lig));
        // Full case folding also expands these ligatures; with folding off,
        // only NFKC makes the pair equal.
        $nfcNoFold = new Config(form: Config::FORM_NFC, caseFold: Config::CASE_OFF);
        $nfkcNoFold = new Config(caseFold: Config::CASE_OFF);
        self::assertGreaterThan(0, self::distance('efficient office files', $lig, $nfcNoFold));
        self::assertSame(0, self::distance('efficient office files', $lig, $nfkcNoFold));
    }

    public function testNfcFixesCompositionOnly(): void
    {
        $composed = "caf\u{00E9} culture weekly";
        $decomposed = "cafe\u{0301} culture weekly";
        $nfc = new Config(form: Config::FORM_NFC);
        self::assertSame(0, self::distance($composed, $decomposed, $nfc));
        self::assertGreaterThan(0, self::distance($composed, $decomposed, new Config(form: Config::FORM_NONE)));
    }

    public function testFullCaseFolding(): void
    {
        self::assertSame(['strasse'], self::tokens('STRASSE'));
        self::assertSame(['strasse'], self::tokens('straße'));
        self::assertSame(0, self::distance('Grosse Strasse heute offen', 'GROSSE STRAßE HEUTE OFFEN'));
        $simple = new Config(caseFold: Config::CASE_SIMPLE);
        self::assertSame(['straße'], self::tokens('straße', $simple));
        self::assertGreaterThan(0, self::distance('Grosse Strasse heute offen', 'GROSSE STRAßE HEUTE OFFEN', $simple));
    }

    public function testTurkishDottedCapitalI(): void
    {
        // Locale-independent: i plus combining dot above, not dotless i.
        self::assertSame(["i\u{0307}stanbul"], self::tokens("\u{0130}stanbul"));
        self::assertSame(['istanbul'], self::tokens('ISTANBUL'));
    }

    public function testCaseFoldingOff(): void
    {
        $off = new Config(caseFold: Config::CASE_OFF);
        self::assertSame(['Monsoon', 'Kerala'], self::tokens('Monsoon Kerala', $off));
        self::assertSame(0, self::distance(Corpus::text('en-03'), Corpus::text('en-04')));
        self::assertGreaterThan(6, self::distance(Corpus::text('en-03'), Corpus::text('en-04'), $off));
    }

    public function testUncasedScriptsUnchangedByFolding(): void
    {
        $off = new Config(caseFold: Config::CASE_OFF);
        foreach (['hi-01', 'ta-02', 'zh-01', 'ar-02'] as $id) {
            self::assertSame(self::tokens(Corpus::text($id), $off), self::tokens(Corpus::text($id)), $id);
        }
    }

    public function testNormaliseFoldNormaliseOrder(): void
    {
        // U+01F0 folds to j + U+030C, which is not in NFC. The second
        // normalisation recomposes it, so every route gives one token.
        $forms = ["\u{01F0}ournal", "j\u{030C}ournal", "J\u{030C}OURNAL"];
        $tokens = array_map(static fn (string $t): array => self::tokens($t), $forms);
        self::assertSame($tokens[0], $tokens[1]);
        self::assertSame($tokens[0], $tokens[2]);
        self::assertTrue(Normalizer::isNormalized($tokens[0][0], Normalizer::FORM_KC));
        self::assertSame("\u{01F0}ournal", $tokens[0][0]);
    }

    public function testEveryTokenIsInNormalForm(): void
    {
        $s = new Simhash();
        foreach (Corpus::titles() as $row) {
            foreach ($s->tokens($row['text']) as $t) {
                self::assertTrue(Normalizer::isNormalized($t, Normalizer::FORM_KC), $row['id'] . ' ' . $t);
            }
        }
    }
}
