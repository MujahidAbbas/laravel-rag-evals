<?php

use App\Models\RagTrace;
use App\Support\TracedRetriever;

/**
 * Observability eval: the offline evals in this suite need a golden label —
 * expected_source, expected_substring — to score a query. Production has no
 * such label. A real customer types a question and there is nothing to compare
 * the answer against, so the offline metrics go dark the moment you ship.
 *
 * This eval asks a different question: is there a signal you CAN compute on
 * every live request, with no label, that tracks retrieval quality? The
 * candidate is the cosine similarity the SearchKnowledgeBase tool already
 * computes and then throws away.
 *
 * The sharpest test of that signal is the out-of-domain question — one the
 * knowledge base simply cannot answer. Retrieval still returns its three
 * nearest chunks and the agent still replies 200 OK, so nothing errors. But if
 * the top similarity on those questions is clearly lower than on real support
 * questions, then a similarity floor is a label-free smoke detector: it flags
 * the query the corpus never covered before the model fabricates an answer.
 *
 * Hits the live OpenAI embeddings API and the real pgvector database, like every
 * eval here. Run `php artisan kb:ingest` and `php artisan migrate` first.
 */
it('flags out-of-domain queries by the similarity score the tool throws away', function () {
    $golden = fn (string $file) => json_decode(
        file_get_contents(base_path("tests/Evals/golden/{$file}")),
        true,
    );

    $inDomain = array_merge($golden('support-questions.json'), $golden('hard-questions.json'));
    $outOfDomain = $golden('out-of-domain-questions.json');

    $retriever = app(TracedRetriever::class);

    RagTrace::truncate();

    // Run both sets through the traced retriever. Every call writes a rag_traces
    // row; we read the signal back off the returned chunks.
    $topSimilarity = function (array $cases) use ($retriever): array {
        $sims = [];
        foreach ($cases as $case) {
            $chunks = $retriever->retrieve($case['question'], 3);
            $sims[] = (float) $chunks->first()->similarity;
        }

        return $sims;
    };

    $inSims = $topSimilarity($inDomain);
    $outSims = $topSimilarity($outOfDomain);

    $mean = fn (array $xs) => array_sum($xs) / count($xs);
    $max = fn (array $xs) => max($xs);

    $inMean = $mean($inSims);
    $outMean = $mean($outSims);

    // A floor drawn between the two populations: the lowest in-domain top-1 vs
    // the highest out-of-domain top-1. If the floor is positive, one threshold
    // cleanly separates "the corpus can answer this" from "it cannot".
    $inFloor = min($inSims);
    $outCeiling = $max($outSims);

    // Every query wrote a trace: the instrumentation is doing its job.
    expect(RagTrace::count())->toBe(count($inDomain) + count($outOfDomain));

    fwrite(STDERR, sprintf(
        "\nObservability eval — top-1 similarity as an out-of-domain detector:\n".
        "  in-domain  (%d questions): mean %.3f, lowest %.3f\n".
        "  out-of-domain (%d questions): mean %.3f, highest %.3f\n".
        "  separation: in-domain mean sits %.3f above out-of-domain mean\n".
        "  clean split? lowest in-domain %.3f %s highest out-of-domain %.3f\n".
        "  traces written: %d rows in rag_traces\n",
        count($inDomain), $inMean, $inFloor,
        count($outOfDomain), $outMean, $outCeiling,
        $inMean - $outMean,
        $inFloor, $inFloor > $outCeiling ? '>' : '<=', $outCeiling,
        RagTrace::count(),
    ));

    // The claim: real support questions retrieve a materially closer top chunk
    // than questions the knowledge base cannot answer. If that holds, the
    // discarded similarity score is a production-ready out-of-domain alarm.
    expect($inMean)->toBeGreaterThan($outMean + 0.05);
})->group('evals');
