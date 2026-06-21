<?php

namespace App\Support;

use App\Ai\Agents\ChunkRanker;
use Illuminate\Support\Collection;

/**
 * Two-stage retrieval: cast a wide net with plain vector search, then let an
 * LLM reorder the candidates by how well they answer the question.
 *
 * Same retrieve() shape as KnowledgeBaseRetriever, so it drops straight into the
 * eval harness as another strategy. Targets the baseline's answer@1 gap — the
 * right document was in the candidate set, just not ranked first.
 */
class RerankRetriever
{
    /** How many candidates to pull from vector search before reranking. */
    private int $candidatePool = 12;

    public function __construct(private KnowledgeBaseRetriever $retriever) {}

    /** Override the candidate-pool size (for the pool-sweep experiment). */
    public function withCandidatePool(int $n): static
    {
        $this->candidatePool = $n;

        return $this;
    }

    /**
     * @return Collection<int, object>
     */
    public function retrieve(string $query, ?int $k = null): Collection
    {
        $k ??= config('knowledge.retrieval_k');

        // Stage 1: wide net. Plain cosine vector search over the chunks.
        $candidates = $this->retriever->retrieve($query, $this->candidatePool)->values();

        if ($candidates->count() <= $k) {
            return $candidates->take($k);
        }

        // Stage 2: an LLM scores the pool and returns indexes best-first.
        $numbered = $candidates
            ->map(fn ($c, $i) => "[{$i}] ({$c->source})\n".trim($c->content))
            ->implode("\n\n");

        $response = (new ChunkRanker)->prompt(<<<PROMPT
            Question: {$query}

            Candidate passages:
            {$numbered}
            PROMPT);

        $order = collect($response['ranking'] ?? [])
            ->filter(fn ($i) => is_int($i) && $candidates->has($i))
            ->unique()
            ->values();

        // Any candidate the model dropped keeps its original vector-search order
        // at the tail, so a forgetful rerank never loses a chunk outright.
        $ordered = $order
            ->map(fn ($i) => $candidates[$i])
            ->concat($candidates->reject(fn ($c, $i) => $order->contains($i))->values());

        return $ordered->take($k)->values();
    }
}
