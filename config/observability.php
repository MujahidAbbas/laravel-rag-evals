<?php

return [
    /*
    | Fraction of live requests to score with the LLM judge. Judging every
    | answer doubles your model spend, so sample. 0.1 = one request in ten.
    */
    'judge_sample_rate' => (float) env('RAG_JUDGE_SAMPLE_RATE', 0.1),

    /*
    | Model pricing in USD per 1,000,000 tokens, used to turn the token counts
    | on every response into a cost per query. There is no cost field in the
    | model response or in the OpenTelemetry GenAI conventions — you compute it
    | from tokens and your own rate card. Swap these for your provider's real
    | numbers; the token counts are measured, the dollars are only as right as
    | the rate you put here.
    */
    'pricing' => [
        'default' => ['input' => 1.25, 'output' => 10.00],
    ],
];
