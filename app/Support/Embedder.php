<?php

namespace App\Support;

use Laravel\Ai\Embeddings;

class Embedder
{
    public function model(): string
    {
        return config('knowledge.embedding_model');
    }

    public function dimensions(): int
    {
        return config('knowledge.embedding_dimensions');
    }

    /**
     * Embed many texts at once. Ingestion and querying both go through here so
     * the model and dimensions can never drift apart between the two.
     *
     * @param  array<int, string>  $texts
     * @return array<int, array<int, float>>
     */
    public function many(array $texts): array
    {
        $pending = Embeddings::for($texts);

        // Only the text-embedding-3-* family accepts a custom dimension count.
        if (str_starts_with($this->model(), 'text-embedding-3')) {
            $pending->dimensions($this->dimensions());
        }

        return $pending->generate(model: $this->model())->embeddings;
    }

    /**
     * @return array<int, float>
     */
    public function one(string $text): array
    {
        return $this->many([$text])[0];
    }

    /**
     * Format a PHP float array as a pgvector literal: [0.1,0.2,...].
     *
     * @param  array<int, float>  $embedding
     */
    public function toVector(array $embedding): string
    {
        return '['.implode(',', $embedding).']';
    }
}
