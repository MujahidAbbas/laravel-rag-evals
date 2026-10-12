<?php

namespace App\Support;

use App\Models\RagTrace;
use Illuminate\Support\Collection;

/**
 * A decorator that wraps the plain retriever and records what it found. Same
 * retrieve(string, ?int) signature as KnowledgeBaseRetriever, so it drops into
 * the tool and the eval with no other change — but on the way through it times
 * the embed and the search, keeps the per-chunk similarity scores the tool
 * discards, and writes one rag_traces row per query.
 *
 * The scores are the whole point. Cosine similarity is computed on every
 * request and then thrown away; kept, it is the label-free signal that tells
 * you retrieval is getting worse before a single customer complains.
 */
class TracedRetriever
{
    public function __construct(
        private Embedder $embedder,
        private KnowledgeBaseRetriever $retriever,
        private TraceContext $context,
    ) {}

    /**
     * @return Collection<int, object>
     */
    public function retrieve(string $query, ?int $k = null): Collection
    {
        $k ??= config('knowledge.retrieval_k');

        // Stage 1: embed the query. Timed on its own so a slow embedding API
        // call never hides behind the search.
        $embedStart = hrtime(true);
        $vector = $this->embedder->toVector($this->embedder->one($query));
        $embedMs = (hrtime(true) - $embedStart) / 1e6;

        // Stage 2: the pgvector nearest-neighbour search.
        $searchStart = hrtime(true);
        $chunks = $this->retriever->search($vector, $k);
        $searchMs = (hrtime(true) - $searchStart) / 1e6;

        $this->record($query, $chunks, $embedMs, $searchMs);

        return $chunks;
    }

    /**
     * @param  Collection<int, object>  $chunks
     */
    private function record(string $query, Collection $chunks, float $embedMs, float $searchMs): void
    {
        $similarities = $chunks->map(fn ($c) => (float) $c->similarity);

        $trace = RagTrace::create([
            'query' => $query,
            'retrieved' => $chunks->values()->map(fn ($c, $rank) => [
                'source' => $c->source,
                'chunk_index' => $c->chunk_index,
                'similarity' => round((float) $c->similarity, 4),
                'rank' => $rank,
                'content' => $c->content,
            ])->all(),
            'retrieved_count' => $chunks->count(),
            'top_similarity' => round((float) $similarities->first(), 4),
            'mean_similarity' => round((float) $similarities->avg(), 4),
            'min_similarity' => round((float) $similarities->min(), 4),
            'embed_ms' => round($embedMs, 2),
            'search_ms' => round($searchMs, 2),
        ]);

        // Hand the row id to the generation listener that fires after this run,
        // so the answer and its token cost land on the same trace as the retrieval.
        $this->context->set($trace->id);
    }
}
