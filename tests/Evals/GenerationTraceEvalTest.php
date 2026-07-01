<?php

use App\Ai\Agents\SupportAgent;
use App\Jobs\EvaluateRagTrace;
use App\Models\RagTrace;

/**
 * Generation-trace eval: runs the full agent on each question and checks that
 * the instrumentation captured what retrieval metrics never see — the answer,
 * its token cost, and a faithfulness score judged against the chunks the model
 * was actually given.
 *
 * The retrieval trace is written inside the SearchKnowledgeBase tool; the
 * TraceGeneration middleware fills in the answer, tokens, and cost on the same
 * row once the model replies. Then EvaluateRagTrace scores faithfulness. In
 * production the judge runs on a sampled fraction from a queue; here we run it
 * on every trace, synchronously, to get a number.
 *
 * Hits the real OpenAI API end to end. Run `php artisan kb:ingest` and
 * `php artisan migrate` first.
 */
it('captures answer, token cost, and faithfulness on every trace', function () {
    // Judge every trace here (production samples); run the queue inline.
    config(['observability.judge_sample_rate' => 0, 'queue.default' => 'sync']);

    $golden = json_decode(
        file_get_contents(base_path('tests/Evals/golden/hard-questions.json')),
        true,
    );

    RagTrace::truncate();

    foreach ($golden as $case) {
        (new SupportAgent)->prompt($case['question']);
    }

    $traces = RagTrace::whereNotNull('answer')->get();

    // Every question produced a fully-filled trace: retrieval + generation.
    expect($traces)->toHaveCount(count($golden));
    expect($traces->every(fn ($t) => $t->input_tokens > 0 && $t->cost_usd > 0))->toBeTrue();

    // Score faithfulness against the retrieved context, reference-free.
    foreach ($traces as $trace) {
        EvaluateRagTrace::dispatch($trace->id);
    }

    $traces = $traces->map(fn ($t) => $t->fresh());

    $mean = fn (string $col) => round($traces->avg($col), $col === 'cost_usd' ? 6 : 1);
    $judged = $traces->whereNotNull('judge_score');
    $faithful = $judged->where('judge_score', '>=', 7)->count();

    fwrite(STDERR, sprintf(
        "\nGeneration trace over %d questions (model %s):\n".
        "  mean input tokens:  %s\n".
        "  mean output tokens: %s\n".
        "  mean cost/query:    \$%s  (at the config rate — swap your own)\n".
        "  faithfulness:       %d/%d scored >= 7/10 (mean score %.1f)\n",
        $traces->count(),
        'gpt-5.4 (from response meta)',
        $mean('input_tokens'),
        $mean('output_tokens'),
        number_format((float) $mean('cost_usd'), 6),
        $faithful, $judged->count(),
        $judged->avg('judge_score') ?? 0,
    ));

    // The judge ran on every trace and wrote a score.
    expect($judged)->toHaveCount($traces->count());
})->group('evals');
