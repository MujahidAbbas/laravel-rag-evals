<?php

namespace App\Console\Commands;

use App\Support\TracedRetriever;
use Illuminate\Console\Command;

/**
 * A label-free health check for retrieval. Runs a set of questions through the
 * traced retriever and reports the average top-1 similarity — the same number
 * you would watch on live traffic. Run it after any change that touches the
 * index (a re-ingest, a chunk-size tweak, an embedding-model swap) to see
 * whether retrieval quietly got worse. No golden labels required.
 */
class ProbeSimilarity extends Command
{
    protected $signature = 'kb:probe {--set=hard-questions.json : Golden fixture of questions to probe}';

    protected $description = 'Report average retrieval similarity for a set of questions (a label-free quality signal)';

    public function handle(TracedRetriever $retriever): int
    {
        $path = base_path("tests/Evals/golden/{$this->option('set')}");

        if (! file_exists($path)) {
            $this->error("Fixture not found: {$path}");

            return self::FAILURE;
        }

        $questions = json_decode(file_get_contents($path), true);

        $topSims = [];

        foreach ($questions as $case) {
            $chunks = $retriever->retrieve($case['question'], 3);
            $topSims[] = (float) $chunks->first()->similarity;
        }

        $mean = array_sum($topSims) / count($topSims);

        $this->info(sprintf(
            'Probed %d questions from %s: mean top-1 similarity %.3f (min %.3f, max %.3f)',
            count($questions),
            $this->option('set'),
            $mean,
            min($topSims),
            max($topSims),
        ));

        return self::SUCCESS;
    }
}
