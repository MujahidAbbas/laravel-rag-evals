<?php

namespace App\Ai\Listeners;

use App\Ai\Agents\SupportAgent;
use App\Jobs\EvaluateRagTrace;
use App\Models\RagTrace;
use App\Support\TokenCost;
use App\Support\TraceContext;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\PromptingAgent;

/**
 * Fills in the generation half of a rag_traces row. On laravel/ai 1.x agent
 * middleware wraps each generation step, so it never sees the whole run. The
 * agent events still do: PromptingAgent fires once before the run, and
 * AgentPrompted fires once after it with the final answer and the token usage
 * summed over every step. The retrieval trace is written in between, deep inside
 * the SearchKnowledgeBase tool, which hands its row id over through TraceContext.
 *
 * Registered in AppServiceProvider, for streamed runs as well.
 */
class TraceGeneration
{
    public function __construct(private TraceContext $context) {}

    public function start(PromptingAgent $event): void
    {
        // Every agent fires these events, the judge and the reranker included.
        if (! $event->prompt->agent instanceof SupportAgent) {
            return;
        }

        // Fresh run: forget any trace id left over from a previous one.
        $this->context->clear();
    }

    public function finish(AgentPrompted $event): void
    {
        // The judge fires AgentPrompted too, while this request's trace id is
        // still set. Without this check its verdict overwrites the answer.
        if (! $event->prompt->agent instanceof SupportAgent) {
            return;
        }

        $traceId = $this->context->id();

        // No trace id means the agent answered without searching the
        // knowledge base — worth knowing, but there is no retrieval row to
        // attach the generation to.
        if ($traceId === null) {
            return;
        }

        $trace = RagTrace::find($traceId);

        if ($trace === null) {
            return;
        }

        $usage = $event->response->usage;

        $trace->update([
            'answer' => $event->response->text,
            'input_tokens' => $usage->inputTokens,
            'output_tokens' => $usage->outputTokens,
            'cost_usd' => TokenCost::usd($event->response->meta->model, $usage),
        ]);

        // Score a sample of live answers for faithfulness, off the request
        // path so the customer never waits on the judge.
        if (mt_rand() / mt_getrandmax() <= (float) config('observability.judge_sample_rate')) {
            EvaluateRagTrace::dispatch($trace->id)->afterResponse();
        }
    }
}
