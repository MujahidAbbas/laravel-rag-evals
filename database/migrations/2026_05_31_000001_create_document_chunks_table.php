<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');

        Schema::create('document_chunks', function (Blueprint $table) {
            $table->id();
            $table->string('source');
            $table->unsignedInteger('chunk_index');
            $table->text('content');
            $table->timestamps();
        });

        // pgvector column. Dimension is config-driven so we can demonstrate the
        // storage-vs-accuracy trade-off. 1536 = OpenAI text-embedding-3-small.
        $dimensions = (int) config('knowledge.embedding_dimensions', 1536);
        DB::statement("ALTER TABLE document_chunks ADD COLUMN embedding vector({$dimensions})");

        // Approximate-nearest-neighbour index using cosine distance (<=>).
        DB::statement('CREATE INDEX document_chunks_embedding_idx ON document_chunks USING hnsw (embedding vector_cosine_ops)');
    }

    public function down(): void
    {
        Schema::dropIfExists('document_chunks');
    }
};
