<?php

use App\Support\KnowledgeBaseRetriever;
use App\Support\RerankRetriever;

/**
 * Experiment: does LLM reranking still saturate the hard set as the candidate
 * pool shrinks? The first rerank run used a pool of 12 (over a 22-chunk corpus),
 * so the answer chunk was almost always already in hand — reranking just promoted
 * it. Starve the pool and we find out whether retrieval recall, not reordering, is
 * the real ceiling on this KB. The pool must exceed k (=3) for reranking to do
 * anything, so the sweep starts at 4.
 *
 * Hard set only (the fair set is already at its ceiling). Hits the live OpenAI API
 * and pgvector. Run `php artisan kb:ingest` first.
 */
it('sweeps the reranker candidate-pool size on the hard set', function () {
    $golden = json_decode(
        file_get_contents(base_path('tests/Evals/golden/hard-questions.json')),
        true,
    );

    $score = function (callable $retrieve) use ($golden): array {
        $total = count($golden);
        $hit1 = $hit3 = $answer1 = 0;

        foreach ($golden as $case) {
            $chunks = $retrieve($case['question'], 3);
            $sources = $chunks->pluck('source')->all();
            $top = $chunks->first();

            if (($sources[0] ?? null) === $case['expected_source']) {
                $hit1++;
            }
            if (in_array($case['expected_source'], $sources, true)) {
                $hit3++;
            }
            if ($top && str_contains($top->content, $case['expected_substring'])) {
                $answer1++;
            }
        }

        return ['hit1' => $hit1 / $total, 'hit3' => $hit3 / $total, 'answer1' => $answer1 / $total];
    };

    $strategies = [
        'baseline' => fn (string $q, int $k) => app(KnowledgeBaseRetriever::class)->retrieve($q, $k),
    ];
    foreach ([4, 6, 8, 12] as $pool) {
        $strategies["rerank pool=$pool"] = fn (string $q, int $k) => app(RerankRetriever::class)
            ->withCandidatePool($pool)
            ->retrieve($q, $k);
    }

    $report = "\nReranker pool sweep — hard set (hit@1 / hit@3 / answer@1)\n\n";
    foreach ($strategies as $name => $retrieve) {
        $r = $score($retrieve);
        $report .= sprintf("%-16s %d%% / %d%% / %d%%\n",
            $name, round($r['hit1'] * 100), round($r['hit3'] * 100), round($r['answer1'] * 100));
    }
    fwrite(STDERR, $report);

    expect(true)->toBeTrue();
});
