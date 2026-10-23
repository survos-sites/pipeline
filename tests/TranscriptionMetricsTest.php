<?php

declare(strict_types=1);

namespace App\Tests;

use App\Benchmark\TranscriptionMetrics;
use PHPUnit\Framework\TestCase;

final class TranscriptionMetricsTest extends TestCase
{
    public function testUnicodeAndWhitespace(): void
    {
        $score = (new TranscriptionMetrics())->score("café\n  one", "cafe\u{0301} one");
        self::assertEquals(0, $score['cer']);
        self::assertEquals(0, $score['wer']);
    }

    public function testSubstitutionInsertionDeletionAndEmptyReference(): void
    {
        $metrics = new TranscriptionMetrics();
        self::assertSame(3, $metrics->score('kitten', 'sitting')['character_errors']);
        self::assertSame(1, $metrics->score('é', 'e')['character_errors']);
        self::assertSame(2, $metrics->score('one two', 'one three four')['word_errors']);
        self::assertEquals(1, $metrics->score('abc', '')['cer']);
        self::assertNull($metrics->score('', 'extra')['cer']);
        self::assertSame(5, $metrics->score('', 'extra')['character_errors']);
    }
}
