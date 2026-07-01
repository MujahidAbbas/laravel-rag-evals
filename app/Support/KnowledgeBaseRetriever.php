<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class KnowledgeBaseRetriever
{
    public function __construct(private Embedder $embedder) {}

    /**
     * Retrieve the $k chunks most similar to the query, nearest first.
     *
     * @return Collection<int, object>
     */
    public function retrieve(string $query, ?int $k = null): Collection
    {
        $k ??= config('knowledge.retrieval_k');

        $vector = $this->embedder->toVector($this->embedder->one($query));

        return $this->search($vector, $k);
    }

    /**
     * Search stored chunks by a pgvector literal, nearest first. Split out from
     * retrieve() so a caller that already holds the query embedding — or one
     * that wants to time the embed and the search separately — can run just
     * this stage.
     *
     * @return Collection<int, object>
     */
    public function search(string $vector, int $k): Collection
    {
        return collect(DB::select(
            'select id, source, chunk_index, content,
                    1 - (embedding <=> ?::vector) as similarity
             from document_chunks
             order by embedding <=> ?::vector
             limit ?',
            [$vector, $vector, $k],
        ));
    }
}
