<?php

namespace App\Jobs;

use App\Ai\Agents\ResponseJudge;
use App\Models\RagTrace;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Scores one trace's answer for faithfulness, off the request path. Offline
 * evals judge against a golden policy document you wrote in advance. Production
 * has no golden answer — so this judges the reply against the chunks the system
 * actually retrieved. That is a reference-free faithfulness check: did the
 * answer stay inside the context it was given, whatever that context was.
 */
class EvaluateRagTrace implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $traceId) {}

    public function handle(): void
    {
        $trace = RagTrace::find($this->traceId);

        if ($trace === null || $trace->answer === null) {
            return;
        }

        // Rebuild the exact context the model saw from the retrieved chunks.
        $context = collect($trace->retrieved)
            ->map(fn ($chunk) => "[{$chunk['source']}]\n{$chunk['content']}")
            ->implode("\n\n---\n\n");

        $verdict = (new ResponseJudge)->prompt(<<<PROMPT
            Question: {$trace->query}

            Retrieved context (the only source of truth the agent was given):
            {$context}

            Support agent reply:
            {$trace->answer}
            PROMPT);

        $trace->update(['judge_score' => $verdict['score']]);
    }
}
