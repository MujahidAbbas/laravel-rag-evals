<?php

use App\Ai\Agents\SupportAgent;
use App\Models\RagTrace;
use App\Support\Embedder;
use App\Support\KnowledgeBaseRetriever;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

/**
 * The generation half of the trace, run through the real OpenAI gateway with
 * HTTP faked: no API spend, no pgvector. The traced retriever still embeds the
 * query (faked over HTTP) and still writes the rag_traces row; only the SQL
 * search is swapped for one fixed chunk.
 *
 * Usage is shaped so the two halves of the cost formula disagree: step one
 * sends 400 uncached tokens, step two sends 1,200 of which 1,024 are cache
 * reads. laravel/ai 1.x counts the cache reads inside inputTokens.
 */
const QUESTION = 'These shoes are too small, can I get the next size up without paying again?';
const ANSWER = 'Yes. Exchanges for a different size are free within 30 days.';

beforeEach(function () {
    config([
        'ai.providers.openai.key' => 'test-key',
        'observability.judge_sample_rate' => 0,
        'observability.pricing' => [
            'default' => ['input' => 1.25, 'cache_read' => 0.125, 'cache_write' => 1.25, 'output' => 10.00],
        ],
    ]);

    Artisan::call('migrate', ['--path' => 'database/migrations/2026_07_01_000001_create_rag_traces_table.php']);

    app()->instance(KnowledgeBaseRetriever::class, new class(app(Embedder::class)) extends KnowledgeBaseRetriever
    {
        public function search(string $vector, int $k): Collection
        {
            return collect([(object) [
                'id' => 1,
                'source' => 'exchanges.md',
                'chunk_index' => 0,
                'content' => 'Exchanges for a different size are free within 30 days of delivery.',
                'similarity' => 0.4144,
            ]]);
        }
    });

    Http::preventStrayRequests();
});

it('writes the answer, tokens and cost onto the retrieval row', function () {
    Http::fake([
        'api.openai.com/v1/embeddings*' => openAiEmbeddingFake(),
        'api.openai.com/v1/responses*' => Http::sequence([
            openAiToolCallFake(),
            openAiAnswerFake(ANSWER, input: 1_200, cached: 1_024, output: 80),
        ]),
    ]);

    (new SupportAgent)->prompt(QUESTION);

    $trace = RagTrace::sole();

    expect($trace->query)->toBe(QUESTION)
        ->and($trace->top_similarity)->toBe(0.4144)
        ->and($trace->answer)->toBe(ANSWER)
        ->and($trace->input_tokens)->toBe(400 + 1_200)
        ->and($trace->output_tokens)->toBe(20 + 80)
        // 576 uncached at 1.25, 1,024 cache reads at 0.125, 100 output at 10.00, per million.
        ->and($trace->cost_usd)->toBe(0.001848);
});

it('keeps the judge run off the trace it is judging', function () {
    config(['observability.judge_sample_rate' => 1]);

    Http::fake([
        'api.openai.com/v1/embeddings*' => openAiEmbeddingFake(),
        'api.openai.com/v1/responses*' => Http::sequence([
            openAiToolCallFake(),
            openAiAnswerFake(ANSWER, input: 1_200, cached: 1_024, output: 80),
            openAiAnswerFake('{"verdict":"pass","score":9,"reasoning":"Stays inside the exchanges policy."}', input: 300, cached: 0, output: 30),
        ]),
    ]);

    (new SupportAgent)->prompt(QUESTION);

    // The sampled judge was dispatched afterResponse(): it runs here, in this
    // process, the way it runs once a web request has sent its response.
    app()->terminate();

    $trace = RagTrace::sole();

    expect($trace->judge_score)->toBe(9)
        ->and($trace->answer)->toBe(ANSWER)
        ->and($trace->input_tokens)->toBe(1_600);

    Http::assertSentCount(4);
});

it('does not write a run that never searched onto the previous row', function () {
    Http::fake([
        'api.openai.com/v1/embeddings*' => openAiEmbeddingFake(),
        'api.openai.com/v1/responses*' => Http::sequence([
            openAiToolCallFake(),
            openAiAnswerFake(ANSWER, input: 1_200, cached: 1_024, output: 80),
            openAiAnswerFake('You are welcome.', input: 250, cached: 0, output: 5),
        ]),
    ]);

    (new SupportAgent)->prompt(QUESTION);
    (new SupportAgent)->prompt('Thanks!');

    expect(RagTrace::sole()->answer)->toBe(ANSWER);
});

it('traces a streamed run too', function () {
    Http::fake([
        'api.openai.com/v1/embeddings*' => openAiEmbeddingFake(),
        'api.openai.com/v1/responses*' => Http::sequence([
            openAiStreamFake([
                ['type' => 'response.created', 'response' => ['id' => 'resp_1', 'model' => 'gpt-6.1-sol', 'status' => 'in_progress', 'output' => []]],
                ['type' => 'response.output_item.added', 'output_index' => 0, 'item' => ['type' => 'function_call', 'id' => 'fc_1', 'call_id' => 'call_1', 'name' => 'SearchKnowledgeBase', 'arguments' => '', 'status' => 'in_progress']],
                ['type' => 'response.function_call_arguments.done', 'item_id' => 'fc_1', 'output_index' => 0, 'arguments' => json_encode(['query' => QUESTION])],
                openAiStreamCompleted('resp_1', input: 400, cached: 0, output: 20, output_items: [
                    ['type' => 'function_call', 'status' => 'completed', 'id' => 'fc_1', 'call_id' => 'call_1', 'name' => 'SearchKnowledgeBase', 'arguments' => json_encode(['query' => QUESTION])],
                ]),
            ]),
            openAiStreamFake([
                ['type' => 'response.created', 'response' => ['id' => 'resp_2', 'model' => 'gpt-6.1-sol', 'status' => 'in_progress', 'output' => []]],
                ['type' => 'response.output_text.delta', 'item_id' => 'msg_1', 'output_index' => 0, 'content_index' => 0, 'delta' => ANSWER],
                ['type' => 'response.output_text.done', 'item_id' => 'msg_1', 'output_index' => 0, 'content_index' => 0, 'text' => ANSWER],
                openAiStreamCompleted('resp_2', input: 1_200, cached: 1_024, output: 80, output_items: [
                    ['type' => 'message', 'status' => 'completed', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => ANSWER]]],
                ]),
            ]),
        ]),
    ]);

    foreach ((new SupportAgent)->stream(QUESTION) as $event) {
        //
    }

    $trace = RagTrace::sole();

    expect($trace->answer)->toBe(ANSWER)
        ->and($trace->input_tokens)->toBe(1_600)
        ->and($trace->output_tokens)->toBe(100)
        ->and($trace->cost_usd)->toBe(0.001848);
});

function openAiEmbeddingFake(): PromiseInterface
{
    return Http::response([
        'object' => 'list',
        'data' => [['object' => 'embedding', 'index' => 0, 'embedding' => [0.1, 0.2, 0.3]]],
        'usage' => ['prompt_tokens' => 18, 'total_tokens' => 18],
    ]);
}

function openAiToolCallFake(): PromiseInterface
{
    return Http::response([
        'id' => 'resp_1',
        'status' => 'completed',
        'model' => 'gpt-6.1-sol',
        'output' => [[
            'type' => 'function_call',
            'id' => 'fc_1',
            'call_id' => 'call_1',
            'name' => 'SearchKnowledgeBase',
            'arguments' => json_encode(['query' => QUESTION]),
            'status' => 'completed',
        ]],
        'usage' => [
            'input_tokens' => 400,
            'output_tokens' => 20,
            'input_tokens_details' => ['cached_tokens' => 0],
        ],
    ]);
}

function openAiAnswerFake(string $text, int $input, int $cached, int $output): PromiseInterface
{
    return Http::response([
        'id' => 'resp_'.md5($text),
        'status' => 'completed',
        'model' => 'gpt-6.1-sol',
        'output' => [[
            'type' => 'message',
            'status' => 'completed',
            'role' => 'assistant',
            'content' => [['type' => 'output_text', 'text' => $text]],
        ]],
        'usage' => [
            'input_tokens' => $input,
            'output_tokens' => $output,
            'input_tokens_details' => ['cached_tokens' => $cached],
        ],
    ]);
}

function openAiStreamFake(array $events): PromiseInterface
{
    $body = collect($events)->map(fn (array $event) => 'data: '.json_encode($event))->implode("\n\n")."\n\n";

    return Http::response($body, 200, ['Content-Type' => 'text/event-stream']);
}

function openAiStreamCompleted(string $id, int $input, int $cached, int $output, array $output_items): array
{
    return [
        'type' => 'response.completed',
        'response' => [
            'id' => $id,
            'model' => 'gpt-6.1-sol',
            'status' => 'completed',
            'output' => $output_items,
            'usage' => [
                'input_tokens' => $input,
                'output_tokens' => $output,
                'input_tokens_details' => ['cached_tokens' => $cached],
            ],
        ],
    ];
}
