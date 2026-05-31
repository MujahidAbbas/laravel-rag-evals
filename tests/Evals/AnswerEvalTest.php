<?php

use App\Ai\Agents\ResponseJudge;
use App\Ai\Agents\SupportAgent;

/**
 * Answer-faithfulness eval: run the FULL agent (retrieval + generation) on each
 * question, then have a second model judge whether the reply is correct and
 * grounded in the policy. Hits the real OpenAI API end to end.
 *
 * This is the layer retrieval metrics cannot see. Retrieval can be perfect and
 * the agent can still answer wrong; retrieval can miss and the agent can still
 * bluff a confident wrong answer. Only judging the final reply catches both.
 * Run `php artisan kb:ingest` first.
 */
it('produces faithful answers grounded in the knowledge base', function () {
    $golden = json_decode(
        file_get_contents(base_path('tests/Evals/golden/support-questions.json')),
        true,
    );

    $total = count($golden);
    $passed = 0;
    $failures = [];

    foreach ($golden as $case) {
        $reply = (new SupportAgent)->prompt($case['question'])->text;

        $policy = file_get_contents(database_path('knowledge/'.$case['expected_source']));

        $verdict = (new ResponseJudge)->prompt(<<<PROMPT
            Question: {$case['question']}

            Official policy text (the single source of truth):
            {$policy}

            Support agent reply:
            {$reply}
            PROMPT);

        if ($verdict['verdict'] === 'pass') {
            $passed++;
        } else {
            $failures[] = "  \"{$case['question']}\"\n    {$verdict['reasoning']}";
        }
    }

    $passRate = $passed / $total;

    fwrite(STDERR, sprintf("\nAnswer faithfulness: %d%% pass (%d/%d)\n", round($passRate * 100), $passed, $total));

    $this->assertGreaterThanOrEqual(
        0.85,
        $passRate,
        sprintf("Answer pass rate fell to %d%% (%d/%d).\nFailures:\n%s",
            round($passRate * 100), $passed, $total, implode("\n", $failures)),
    );
});
