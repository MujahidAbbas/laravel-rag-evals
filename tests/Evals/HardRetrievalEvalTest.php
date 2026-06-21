<?php

use App\Support\KnowledgeBaseRetriever;

/**
 * Hard retrieval eval: the same three metrics as RetrievalEvalTest, but run over
 * TWO fixtures and (as you build them) several retrieval strategies side by side.
 *
 *   support-questions.json — the "fair" set. Questions reuse the docs' own words,
 *     so plain vector search is already at its ceiling here. This is the guard:
 *     a query-side technique must not REGRESS these.
 *   hard-questions.json — deliberately messy. Colloquial, cross-document, and
 *     vocabulary-mismatched questions where the words in the question and the words
 *     in the answer document barely overlap ("send it back for my money" when the
 *     doc only ever says "return" and "refund"). This is where query rewriting earns
 *     its keep.
 *
 * Baseline vector search is expected to score LOW on the hard set — that low number
 * is the "before". As you implement HyDE / multi-query / RAG-Fusion in the post, give
 * each a class with a `retrieve(string $query, int $k)` method, register it in
 * $strategies below, and re-run: the hard-set hit@1 should climb while the fair-set
 * number holds.
 *
 * Hits the live OpenAI embeddings API and the real pgvector database, like every eval
 * in this suite. Run `php artisan kb:ingest` first.
 */
it('measures retrieval strategies across the fair and hard sets', function () {
    $goldenSet = fn (string $file) => json_decode(
        file_get_contents(base_path("tests/Evals/golden/{$file}")),
        true,
    );

    // Score one retrieval strategy over one golden set.
    $score = function (callable $retrieve, array $golden): array {
        $total = count($golden);
        $hit1 = $hit3 = $answer1 = 0;
        $misses = [];

        foreach ($golden as $case) {
            $chunks = $retrieve($case['question'], 3);
            $sources = $chunks->pluck('source')->all();
            $top = $chunks->first();

            if (($sources[0] ?? null) === $case['expected_source']) {
                $hit1++;
            } else {
                $misses[] = "    \"{$case['question']}\"\n"
                    ."      expected {$case['expected_source']}, top was ".($sources[0] ?? 'none');
            }

            if (in_array($case['expected_source'], $sources, true)) {
                $hit3++;
            }

            if ($top && str_contains($top->content, $case['expected_substring'])) {
                $answer1++;
            }
        }

        return [
            'total' => $total,
            'hit1' => $hit1 / $total,
            'hit3' => $hit3 / $total,
            'answer1' => $answer1 / $total,
            'misses' => $misses,
        ];
    };

    // Strategies to compare. Baseline is the production retriever. The technique
    // strategies are added by the post; each is just a class with the same
    // retrieve(string $query, int $k) shape, so it slots in here the moment it
    // exists. The class_exists guard keeps this test green before they're built.
    $strategies = [
        'baseline (vector search)' => fn (string $q, int $k) => app(KnowledgeBaseRetriever::class)->retrieve($q, $k),
    ];

    foreach ([
        \App\Support\RerankRetriever::class => 'LLM rerank',
        \App\Support\HydeRetriever::class => 'HyDE',
        \App\Support\MultiQueryRetriever::class => 'multi-query',
        \App\Support\RagFusionRetriever::class => 'RAG-Fusion',
    ] as $class => $label) {
        if (class_exists($class)) {
            $strategies[$label] = fn (string $q, int $k) => app($class)->retrieve($q, $k);
        }
    }

    $fixtures = [
        'fair' => $goldenSet('support-questions.json'),
        'hard' => $goldenSet('hard-questions.json'),
    ];

    $results = [];
    $report = "\nRetrieval eval — source hit@1 / hit@3 / answer@1\n\n";
    $report .= sprintf("%-28s %-22s %-22s\n", 'strategy', 'fair set', 'hard set');
    $report .= str_repeat('-', 72)."\n";

    foreach ($strategies as $name => $retrieve) {
        $fair = $score($retrieve, $fixtures['fair']);
        $hard = $score($retrieve, $fixtures['hard']);
        $results[$name] = ['fair' => $fair, 'hard' => $hard];

        $fmt = fn (array $r) => sprintf('%d%% / %d%% / %d%%',
            round($r['hit1'] * 100), round($r['hit3'] * 100), round($r['answer1'] * 100));

        $report .= sprintf("%-28s %-22s %-22s\n", $name, $fmt($fair), $fmt($hard));
    }

    // Show exactly which hard questions the baseline whiffs on — this is the list
    // the query-side techniques have to rescue, and the raw material for the post.
    $report .= "\nbaseline misses on the hard set:\n"
        .implode("\n", $results['baseline (vector search)']['hard']['misses'])."\n";

    fwrite(STDERR, $report);

    $fairBaseline = $results['baseline (vector search)']['fair'];
    $hardBaseline = $results['baseline (vector search)']['hard'];

    // The fair set is the regression guard: plain vector search already aces it, and
    // nothing here may drag it down. Same 0.85 bar as RetrievalEvalTest.
    $this->assertGreaterThanOrEqual(
        0.85,
        $fairBaseline['hit1'],
        sprintf('Fair-set source hit@1 fell to %d%% — a strategy regressed the easy questions.',
            round($fairBaseline['hit1'] * 100)),
    );

    // The hard set has NO high gate on the baseline — a low score is the point. It
    // only has to (a) actually run against pgvector and (b) be genuinely harder than
    // the fair set, so there is headroom for the techniques to prove themselves. If
    // this fails, the fixture is too easy: make the questions messier.
    $this->assertGreaterThan(0, $hardBaseline['total']);
    $this->assertLessThan(
        $fairBaseline['hit1'],
        $hardBaseline['hit1'],
        sprintf('Hard set is not actually harder (hit@1 %d%% vs fair %d%%) — strengthen the fixture.',
            round($hardBaseline['hit1'] * 100), round($fairBaseline['hit1'] * 100)),
    );
});
