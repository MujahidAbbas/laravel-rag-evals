<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rag_traces', function (Blueprint $table) {
            $table->id();

            // What was asked, and what came back. The `retrieved` JSON holds the
            // per-chunk similarity + rank the SearchKnowledgeBase tool computes
            // and then throws away before the model ever sees it.
            $table->text('query');
            $table->json('retrieved');
            $table->unsignedInteger('retrieved_count');

            // The label-free signals you can watch on live traffic with no golden
            // set: how close the best match was, and the spread of the top-k.
            $table->double('top_similarity');
            $table->double('mean_similarity');
            $table->double('min_similarity');

            // Per-stage latency, split so a slow embed and a slow pgvector search
            // are never hidden behind one number.
            $table->double('embed_ms');
            $table->double('search_ms');

            // Generation-side columns, filled in by the agent middleware once the
            // answer comes back. Nullable because the retrieval trace is written
            // first, before the model has run.
            $table->text('answer')->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->double('cost_usd')->nullable();
            $table->unsignedTinyInteger('judge_score')->nullable();

            $table->timestamps();

            // You will query this table by "how bad did it get, and when" — a
            // similarity floor over a time window — so index both.
            $table->index(['created_at', 'top_similarity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rag_traces');
    }
};
