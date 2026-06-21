<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Listwise LLM reranker. Given a question and a numbered list of candidate
 * passages, it returns every candidate's number ordered most-relevant first.
 *
 * This is the "result-side" lever: it cannot retrieve a chunk vector search
 * missed, but it can promote the answer-bearing chunk that vector search ranked
 * too low. One call scores the whole candidate pool.
 */
class ChunkRanker implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<'PROMPT'
            You rerank candidate knowledge-base passages by how well each one
            ANSWERS the customer's question — not by how many words it shares
            with the question. A passage that directly contains the answer ranks
            above one that is merely on the same topic.

            You are given the question and a list of candidate passages, each
            tagged with a number like [0], [1], [2]. Return every candidate's
            number in a single ordered list, most relevant first. Include all of
            the numbers exactly once.
            PROMPT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'ranking' => $schema->array()->items($schema->integer())->required(),
        ];
    }
}
