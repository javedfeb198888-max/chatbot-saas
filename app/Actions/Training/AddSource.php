<?php

namespace App\Actions\Training;

use App\Jobs\TrainSource;
use App\Models\Bot;
use App\Models\Source;
use Illuminate\Validation\ValidationException;

class AddSource
{
    /**
     * @param  array{type: string, url?: ?string, title?: ?string, content?: ?string}  $data
     */
    public function handle(Bot $bot, array $data): Source
    {
        if ($data['type'] === 'url') {
            $this->guardPageLimit($bot, $data['url']);
        }

        $source = Source::create([
            'bot_id' => $bot->id,
            'type' => $data['type'],
            'url' => $data['url'] ?? null,
            'title' => $data['title'] ?? null,
            'content' => $data['content'] ?? null,
            'status' => 'pending',
        ]);

        TrainSource::dispatch($source->id, $source->client_id);

        return $source;
    }

    private function guardPageLimit(Bot $bot, string $url): void
    {
        $pages = Source::where('bot_id', $bot->id)->where('type', 'url');

        if ((clone $pages)->where('url', $url)->exists()) {
            throw ValidationException::withMessages(['url' => 'This page has already been added.']);
        }

        $limit = (int) config('chatbot.training.max_pages_per_bot');

        if ($pages->count() >= $limit) {
            throw ValidationException::withMessages(['url' => "You have reached the limit of {$limit} pages."]);
        }
    }
}
