# Laravel RAG Evals

A small but real RAG system built with the [Laravel AI SDK](https://laravel.com/docs/13.x/ai-sdk)
and pgvector, plus the eval suite that tells you whether retrieval is actually any
good. Companion code for a series on RAG in Laravel (see [Articles](#articles) below).

A support agent answers questions from a 9-document knowledge base (returns,
shipping, refunds, warranty, account, exchanges, damaged items, promotions,
privacy). The interesting part is `tests/Evals/` — it measures retrieval quality
against real OpenAI embeddings and a real pgvector database.

## Articles

This repo is the demo for a series on RAG in Laravel. Build it, prove it, watch it in production:

1. [Building RAG in Laravel: Four Ingestion Bugs That Silently Wreck Retrieval](https://mujahidabbas.dev/blog/building-rag-laravel-pgvector/) — the ingestion pipeline that decides retrieval quality before the model runs: chunking, the pgvector index operator, embedding dimensions, and the model lock.
2. [Evaluating RAG in Laravel: Is Your Agent Retrieving the Right Chunks?](https://mujahidabbas.dev/blog/evaluating-rag-laravel/) — the eval suite in `tests/Evals/` that measures retrieval quality and catches silent regressions.
3. [Reranking RAG in Laravel: The Right Document, the Wrong Paragraph](https://mujahidabbas.dev/blog/rag-reranking-laravel/) — a two-stage reranker (`RerankRetriever` + `ChunkRanker`) that reorders the candidate pool, measured against a deliberately messy golden set.
4. [Observing RAG in Laravel: Your Retrieval Is Getting Worse and Nothing Throws](https://mujahidabbas.dev/blog/rag-observability-laravel/) — trace retrieval quality, token cost, and faithfulness on live traffic with `TracedRetriever`, a `rag_traces` table, and the `kb:probe` command.

## Requirements

- PHP 8.4+, Composer
- PostgreSQL with the `pgvector` extension
- An OpenAI API key with a little credit (the evals hit the real API on purpose)

## Setup

```bash
composer install
cp .env.example .env && php artisan key:generate

# Postgres + pgvector (macOS / Homebrew)
brew install postgresql@17 pgvector
brew services start postgresql@17
createdb laravel_rag_evals

# .env
#   DB_CONNECTION=pgsql
#   DB_DATABASE=laravel_rag_evals
#   DB_USERNAME=<your os user>     # Homebrew Postgres superuser, not "root"
#   OPENAI_API_KEY=sk-...

php artisan migrate
php artisan kb:ingest        # chunk -> embed -> store
```

## Run the evals

```bash
php artisan test --testsuite=Evals
```

You'll see something like:

```
source hit@1: 94% (30/32)
source hit@3: 100% (32/32)
answer@1:     84% (27/32)
Answer faithfulness: 94% pass (30/32)
```

## Watch the eval catch a regression

Cut the embedding dimensions — a realistic "shrink the index to save storage" move —
and watch retrieval quality collapse:

```bash
KB_EMBEDDING_DIMENSIONS=16 php artisan migrate:fresh --force
KB_EMBEDDING_DIMENSIONS=16 php artisan kb:ingest
KB_EMBEDDING_DIMENSIONS=16 php artisan test --testsuite=Evals
# source hit@1 drops to ~47% — the suite goes red and names the failing questions

# restore
php artisan migrate:fresh --force && php artisan kb:ingest
```

## Observe retrieval on live traffic

The evals above run offline, against a golden set. Production has no labels.
`TracedRetriever` records one `rag_traces` row per query: the retrieved chunks with
their similarity scores and ranks, per-stage latency, and — via the `TraceGeneration`
agent middleware — the answer, its token cost, and a sampled faithfulness score.

```bash
php artisan migrate                              # creates rag_traces
php artisan kb:probe --set=hard-questions.json   # mean top-1 similarity, a label-free health check
php artisan test --testsuite=Evals               # includes the observability + generation-trace evals
```

`kb:probe` prints the average top-1 similarity for a question set. Run it after any
re-ingest and a silent retrieval regression shows up the same day, before a single
customer complains.

## Configuration

`config/knowledge.php` (env-driven):

- `KB_EMBEDDING_MODEL` (default `text-embedding-3-small`)
- `KB_EMBEDDING_DIMENSIONS` (default `1536`)
- `KB_RETRIEVAL_K` (default `4`)

> Ingestion and querying read the same config, so the stored vectors and the query
> vector can never use a different model or dimension. That drift is a classic
> source of silent garbage retrieval.
