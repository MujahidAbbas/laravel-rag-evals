<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per RAG request: the query, the chunks it retrieved with their
 * similarity scores and ranks, and the label-free signals you can watch in
 * production without a golden set. This is the record the SearchKnowledgeBase
 * tool never kept.
 */
class RagTrace extends Model
{
    protected $fillable = [
        'query',
        'retrieved',
        'retrieved_count',
        'top_similarity',
        'mean_similarity',
        'min_similarity',
        'embed_ms',
        'search_ms',
        'answer',
        'input_tokens',
        'output_tokens',
        'cost_usd',
        'judge_score',
    ];

    protected $casts = [
        'retrieved' => 'array',
        'top_similarity' => 'float',
        'mean_similarity' => 'float',
        'min_similarity' => 'float',
        'embed_ms' => 'float',
        'search_ms' => 'float',
        'cost_usd' => 'float',
    ];
}
