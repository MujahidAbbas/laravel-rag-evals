<?php

namespace App\Ai\Agents;

use App\Ai\Middleware\TraceGeneration;
use App\Ai\Tools\SearchKnowledgeBase;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Promptable;
use Stringable;

class SupportAgent implements Agent, HasMiddleware, HasTools
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<'PROMPT'
            You are a customer support agent. Answer questions using only the
            company knowledge base. Always call the SearchKnowledgeBase tool
            before answering, and base your reply only on what it returns. If
            the knowledge base does not contain the answer, say you are not sure
            and offer to connect the customer to a human. Keep answers short.
            PROMPT;
    }

    /**
     * @return Tool[]
     */
    public function tools(): iterable
    {
        return [new SearchKnowledgeBase];
    }

    /**
     * Wrap every run so retrieval, the answer, and its token cost land on one
     * rag_traces row.
     */
    public function middleware(): array
    {
        return [app(TraceGeneration::class)];
    }
}
