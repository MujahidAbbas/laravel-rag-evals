<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentChunk extends Model
{
    protected $fillable = ['source', 'chunk_index', 'content'];

    // The embedding column is a pgvector type, written via raw SQL during
    // ingestion, so it is intentionally not mass-assignable here.
}
