<?php

namespace App\Console\Commands;

use App\Support\Chunker;
use App\Support\Embedder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class IngestKnowledge extends Command
{
    protected $signature = 'kb:ingest {--chunk-size=600 : Maximum characters per chunk}';

    protected $description = 'Chunk, embed, and store the support knowledge base';

    public function handle(Chunker $chunker, Embedder $embedder): int
    {
        $maxChars = (int) $this->option('chunk-size');

        $files = glob(database_path('knowledge/*.md'));

        if (empty($files)) {
            $this->error('No knowledge files found in database/knowledge/.');

            return self::FAILURE;
        }

        DB::table('document_chunks')->truncate();

        $stored = 0;

        foreach ($files as $file) {
            $source = basename($file);
            $chunks = $chunker->chunk(file_get_contents($file), $maxChars);

            // One embedding request for every chunk in the file.
            $embeddings = $embedder->many($chunks);

            foreach ($chunks as $index => $content) {
                DB::insert(
                    'insert into document_chunks (source, chunk_index, content, embedding, created_at, updated_at)
                     values (?, ?, ?, ?::vector, now(), now())',
                    [$source, $index, $content, $embedder->toVector($embeddings[$index])],
                );

                $stored++;
            }

            $this->line("  {$source}: ".count($chunks).' chunks');
        }

        $this->info("Ingested {$stored} chunks from ".count($files)." files "
            ."(model {$embedder->model()}, {$embedder->dimensions()} dims, chunk size {$maxChars}).");

        return self::SUCCESS;
    }
}
