<?php

use App\Support\KnowledgeBaseRetriever;

/**
 * Retrieval eval: does vector search surface the RIGHT context for a real
 * support question? Hits the live OpenAI embeddings API and the real pgvector
 * database. Run `php artisan kb:ingest` first.
 *
 * Three metrics per question:
 *   source hit@1 — was the single nearest chunk from the correct document?
 *   source hit@3 — was the correct document anywhere in the top 3?
 *   answer@1     — did the top chunk actually contain the answer phrase?
 *
 * source hit@1 tells you ranking is right; answer@1 is stricter — it catches
 * the case where retrieval picks the right document but the wrong passage.
 */
it('retrieves the correct context for support questions', function () {
    $golden = json_decode(
        file_get_contents(base_path('tests/Evals/golden/support-questions.json')),
        true,
    );

    $retriever = app(KnowledgeBaseRetriever::class);

    $total = count($golden);
    $sourceHit1 = 0;
    $sourceHit3 = 0;
    $answerHit1 = 0;
    $sourceMisses = [];
    $answerMisses = [];

    foreach ($golden as $case) {
        $chunks = $retriever->retrieve($case['question'], 3);
        $sources = $chunks->pluck('source')->all();
        $top = $chunks->first();

        if (($sources[0] ?? null) === $case['expected_source']) {
            $sourceHit1++;
        } else {
            $sourceMisses[] = "  \"{$case['question']}\"\n"
                ."    expected {$case['expected_source']}, top result was ".($sources[0] ?? 'none');
        }

        if (in_array($case['expected_source'], $sources, true)) {
            $sourceHit3++;
        }

        if ($top && str_contains($top->content, $case['expected_substring'])) {
            $answerHit1++;
        } else {
            $answerMisses[] = "  \"{$case['question']}\"\n"
                ."    top chunk ({$top?->source}) did not contain \"{$case['expected_substring']}\"";
        }
    }

    $sourceRate1 = $sourceHit1 / $total;
    $sourceRate3 = $sourceHit3 / $total;
    $answerRate1 = $answerHit1 / $total;

    fwrite(STDERR, sprintf(
        "\nRetrieval eval over %d questions:\n  source hit@1: %d%% (%d/%d)\n  source hit@3: %d%% (%d/%d)\n  answer@1:     %d%% (%d/%d)\n",
        $total,
        round($sourceRate1 * 100), $sourceHit1, $total,
        round($sourceRate3 * 100), $sourceHit3, $total,
        round($answerRate1 * 100), $answerHit1, $total,
    ));

    $this->assertGreaterThanOrEqual(
        0.85,
        $sourceRate1,
        sprintf("Top-result accuracy (source hit@1) fell to %d%% (%d/%d).\nWrong top result for:\n%s",
            round($sourceRate1 * 100), $sourceHit1, $total, implode("\n", $sourceMisses)),
    );

    $this->assertGreaterThanOrEqual(
        0.80,
        $answerRate1,
        sprintf("Answer-passage accuracy (answer@1) fell to %d%% (%d/%d).\nTop chunk missed the answer for:\n%s",
            round($answerRate1 * 100), $answerHit1, $total, implode("\n", $answerMisses)),
    );
});
