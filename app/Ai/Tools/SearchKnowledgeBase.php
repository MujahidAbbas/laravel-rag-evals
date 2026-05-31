<?php

namespace App\Ai\Tools;

use App\Support\KnowledgeBaseRetriever;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class SearchKnowledgeBase implements Tool
{
    public function description(): Stringable|string
    {
        return 'Search the support knowledge base for policy information about returns, '
            .'shipping, refunds, billing, warranty, and account management. Always call '
            .'this before answering a customer question.';
    }

    public function handle(Request $request): Stringable|string
    {
        $chunks = app(KnowledgeBaseRetriever::class)->retrieve($request['query']);

        if ($chunks->isEmpty()) {
            return 'No relevant policy information was found.';
        }

        return $chunks
            ->map(fn ($chunk) => "[{$chunk->source}]\n{$chunk->content}")
            ->implode("\n\n---\n\n");
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->required(),
        ];
    }
}
