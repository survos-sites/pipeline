<?php

declare(strict_types=1);

namespace App\Benchmark;

/** Unicode edit distance; references never enter model input. */
final class TranscriptionMetrics
{
    public function score(string $reference, string $prediction): array
    {
        $reference = $this->normalize($reference);
        $prediction = $this->normalize($prediction);
        $characters = preg_split('//u', $reference, -1, PREG_SPLIT_NO_EMPTY);
        $words = '' === $reference ? [] : explode(' ', $reference);
        $charErrors = $this->distance($characters, preg_split('//u', $prediction, -1, PREG_SPLIT_NO_EMPTY));
        $wordErrors = $this->distance($words, '' === $prediction ? [] : explode(' ', $prediction));

        return [
            'reference_characters' => count($characters), 'character_errors' => $charErrors,
            'cer' => count($characters) ? $charErrors / count($characters) : null,
            'reference_words' => count($words), 'word_errors' => $wordErrors,
            'wer' => count($words) ? $wordErrors / count($words) : null,
        ];
    }

    public function normalize(string $text): string
    {
        // Deliberately retain case, punctuation, historic spelling and Markdown.
        return trim(preg_replace('/\s+/u', ' ', \Normalizer::normalize($text, \Normalizer::FORM_C)));
    }

    private function distance(array $a, array $b): int
    {
        $previous = range(0, count($b));
        foreach ($a as $i => $left) {
            $current = [$i + 1];
            foreach ($b as $j => $right) {
                $current[] = min($current[$j] + 1, $previous[$j + 1] + 1, $previous[$j] + ($left === $right ? 0 : 1));
            }
            $previous = $current;
        }
        return $previous[count($b)];
    }
}
