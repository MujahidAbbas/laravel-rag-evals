<?php

namespace App\Support;

class Chunker
{
    /**
     * Split text into chunks no larger than $maxChars, breaking on paragraph
     * boundaries so a chunk never splits a sentence in half.
     *
     * @return array<int, string>
     */
    public function chunk(string $text, int $maxChars = 600): array
    {
        $paragraphs = preg_split('/\n\s*\n/', trim($text));
        $chunks = [];
        $current = '';

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);

            if ($paragraph === '') {
                continue;
            }

            if ($current !== '' && mb_strlen($current) + mb_strlen($paragraph) + 2 > $maxChars) {
                $chunks[] = $current;
                $current = '';
            }

            $current = $current === '' ? $paragraph : $current."\n\n".$paragraph;
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }
}
