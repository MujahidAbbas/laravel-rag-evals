<?php

namespace App\Support;

/**
 * Carries the current request's rag_traces row id from the retrieval stage
 * (deep inside a tool call) out to the generation listener that fires after
 * the whole agent run. The tool and the listener never call each other directly,
 * so this request-scoped holder is the thread that ties the retrieval trace to
 * the answer, the tokens, and the cost that follow it.
 *
 * Bound as a singleton (one instance per request) in AppServiceProvider.
 */
class TraceContext
{
    private ?int $traceId = null;

    public function set(int $traceId): void
    {
        $this->traceId = $traceId;
    }

    public function id(): ?int
    {
        return $this->traceId;
    }

    public function clear(): void
    {
        $this->traceId = null;
    }
}
