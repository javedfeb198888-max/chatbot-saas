<?php

namespace App\Chat;

use App\Casts\Vector;
use App\Models\Bot;
use App\Models\Chunk;
use App\Training\Embedder;
use Illuminate\Support\Collection;

class Retriever
{
    public function __construct(private Embedder $embedder)
    {
    }

    /**
     * The training chunks closest in meaning to the question.
     * Always filtered to one bot, on top of the client scope on Chunk.
     *
     * @return Collection<int, Chunk>
     */
    public function search(Bot $bot, string $question): Collection
    {
        if (! Chunk::where('bot_id', $bot->id)->exists()) {
            return collect();
        }

        $vector = Vector::toSql($this->embedder->embed([$question])[0]);

        return Chunk::query()
            ->where('bot_id', $bot->id)
            ->select(['id', 'source_id', 'content', 'metadata'])
            ->selectRaw('embedding <=> ? as distance', [$vector])
            ->orderBy('distance')
            ->limit((int) config('chatbot.chat.max_chunks'))
            ->get();
    }
}
