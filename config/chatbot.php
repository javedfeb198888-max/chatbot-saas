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

    /*
    | The chat model that answers visitors. A bot can override the model in
    | its own settings; otherwise these defaults apply.
    */
    'chat' => [
        'provider' => env('CHATBOT_CHAT_PROVIDER', 'anthropic'),
        'model' => env('CHATBOT_CHAT_MODEL', 'claude-haiku-5-5'),

        'max_chunks' => 6,          // training chunks sent with each question
        'history_messages' => 10,   // earlier messages sent with each question
        'max_message_chars' => 2000,

        'rate_limit' => [
            'per_visitor_per_minute' => 15,
            'per_bot_per_minute' => 300,
        ],

        // USD per 1 million tokens [input, output], used only to track cost.
        'prices' => [
            'claude-haiku-5-5' => [0.10, 0.50],
        ],
        'default_price' => [1.00, 5.00],
    ],

    'calcom' => [
        'base_url' => env('CALCOM_BASE_URL', 'https://api.cal.com/v2'),
        'slots_version' => '2024-09-04',
        'bookings_version' => '2024-08-13',
        'timeout' => 15,
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
