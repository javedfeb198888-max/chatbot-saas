<?php

return [

    /*
    | Embeddings turn text into vectors for search. The chunks table stores
    | 1536 numbers per chunk, so the model must produce 1536 dimensions.
    */
    'embeddings' => [
        'provider' => env('CHATBOT_EMBEDDING_PROVIDER', 'openai'),
        'model' => env('CHATBOT_EMBEDDING_MODEL', 'text-embedding-3-small'),
        'dimensions' => 1536,
        'batch_size' => 64,
    ],

    'training' => [
        // V1 limit on website pages per bot.
        'max_pages_per_bot' => (int) env('CHATBOT_MAX_PAGES_PER_BOT', 200),

        // About 500 tokens per chunk with a small overlap between chunks.
        'chunk_words' => 350,
        'chunk_overlap_words' => 40,

        'fetch_timeout' => 15,
        'max_page_bytes' => 2 * 1024 * 1024,
        'max_redirects' => 3,
        'user_agent' => env('CHATBOT_CRAWLER_USER_AGENT', 'ChatbotSaaSBot/1.0'),
    ],

];
