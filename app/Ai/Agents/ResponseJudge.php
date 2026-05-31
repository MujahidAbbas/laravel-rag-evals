<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

class ResponseJudge implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<'PROMPT'
            You grade a customer support reply for FAITHFULNESS to company policy.
            You are given the customer's question, the official policy text, and
            the reply the agent gave. The policy text is the single source of truth.

            Pass the reply if BOTH are true:
            - It answers the question.
            - Every factual claim it makes is supported by the policy text.

            A reply that includes extra detail is fine, as long as that detail
            appears in the policy text. Do not penalize a reply for being more
            thorough than the question strictly required.

            Fail the reply only if it contradicts the policy text, does not answer
            the question, or states a detail the policy text does not support.

            Return a verdict, a 1-to-10 score, and one sentence of reasoning.
            PROMPT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'verdict' => $schema->string()->enum(['pass', 'fail'])->required(),
            'score' => $schema->integer()->min(1)->max(10)->required(),
            'reasoning' => $schema->string()->required(),
        ];
    }
}
