<?php

return [
    /*
    | The OpenAI embedding model used for BOTH ingestion and querying. These
    | MUST match: embedding a query with a different model or dimension than
    | the stored chunks produces meaningless distances and silent garbage.
    */
    'embedding_model' => env('KB_EMBEDDING_MODEL', 'text-embedding-3-small'),

    /*
    | Vector dimensions. The text-embedding-3-* models let you shorten the
    | vector to trade accuracy for storage and speed. ada-002 is fixed at 1536.
    */
    'embedding_dimensions' => (int) env('KB_EMBEDDING_DIMENSIONS', 1536),

    /*
    | How many chunks each retrieval returns to the agent.
    */
    'retrieval_k' => (int) env('KB_RETRIEVAL_K', 4),
];
