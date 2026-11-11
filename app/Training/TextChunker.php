<?php

namespace App\Training;

/**
 * Cuts long text into pieces of about chunk_words words. Each new piece
 * repeats the last few words of the one before, so a sentence that falls
 * on a boundary is still found by search.
 */
class TextChunker
{
    /** @return array<int, string> */
    public function chunk(string $text): array
    {
        $size = (int) config('chatbot.training.chunk_words');
        $overlap = (int) config('chatbot.training.chunk_overlap_words');

        $paragraphs = array_values(array_filter(array_map('trim', preg_split('/\n{2,}/', $text) ?: [])));

        $chunks = [];
        $current = [];

        foreach ($paragraphs as $paragraph) {
            $words = preg_split('/\s+/u', $paragraph, -1, PREG_SPLIT_NO_EMPTY) ?: [];

            // A single paragraph longer than a chunk is cut into slices.
            foreach (array_chunk($words, $size) as $slice) {
                if ($current !== [] && count($current) + count($slice) > $size) {
                    $chunks[] = implode(' ', $current);
                    $current = array_slice($current, -$overlap);
                }

                if ($current !== []) {
                    $current[] = "\n";
                }

                array_push($current, ...$slice);
            }
        }

        if ($this->wordCount($current) > 0) {
            $chunks[] = implode(' ', $current);
        }

        return array_values(array_filter(array_map(
            fn (string $chunk) => trim(preg_replace('/ ?\n ?/', "\n", $chunk) ?? ''),
            $chunks,
        )));
    }

    /** Counts words in any language (str_word_count only understands Latin letters). */
    public static function countWords(string $text): int
    {
        return preg_match_all('/\S+/u', $text) ?: 0;
    }

    /** @param  array<int, string>  $words */
    private function wordCount(array $words): int
    {
        return count(array_filter($words, fn (string $word) => $word !== "\n"));
    }
}
