<?php

declare(strict_types=1);

namespace IndianOpinion\Simhash;

/**
 * One token as fed to feature building.
 *
 * Kinds: `word` (a run of letters, digits and marks in one script), `cjk`
 * (a grapheme bigram from a script written without spaces), `url` (a host
 * name kept whole), `emoji` (one emoji sequence, only with emoji = keep).
 */
final class Token
{
    public const KIND_WORD = 'word';
    public const KIND_CJK = 'cjk';
    public const KIND_URL = 'url';
    public const KIND_EMOJI = 'emoji';

    public function __construct(
        public readonly string $text,
        public readonly string $kind,
        public readonly string $script,
        public readonly int $segment,
    ) {
    }
}
