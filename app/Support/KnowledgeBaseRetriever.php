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
