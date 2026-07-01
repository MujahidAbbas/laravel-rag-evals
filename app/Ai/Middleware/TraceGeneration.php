<?php

namespace App\Ai\Middleware;

use App\Jobs\EvaluateRagTrace;
use App\Models\RagTrace;
use App\Support\TokenCost;
use App\Support\TraceContext;
use Closure;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;

/**
 * Wraps the whole agent run. The retrieval trace is written first, deep inside
 * the SearchKnowledgeBase tool; by the time this middleware's `then` callback
 * fires, the model has answered, so its token usage and cost land on the same
 * rag_traces row. Then a sampled fraction of requests is handed to a queued
 * judge that scores the answer's faithfulness off the request path.
 */
class TraceGeneration
{
    public function __construct(private TraceContext $context) {}

    public function handle(AgentPrompt $prompt, Closure $next)
    {
        // Fresh request: forget any trace id left over from a previous run.
        $this->context->clear();

        return $next($prompt)->then(function (AgentResponse $response) {
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

            $trace->update([
                'answer' => $response->text,
                'input_tokens' => $response->usage->promptTokens,
                'output_tokens' => $response->usage->completionTokens,
                'cost_usd' => TokenCost::usd($response->meta->model, $response->usage),
            ]);

            // Score a sample of live answers for faithfulness, off the request
            // path so the customer never waits on the judge.
            if (mt_rand() / mt_getrandmax() <= (float) config('observability.judge_sample_rate')) {
                EvaluateRagTrace::dispatch($trace->id)->afterResponse();
            }
        });
    }
}
