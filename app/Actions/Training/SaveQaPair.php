<?php

namespace App\Actions\Training;

use App\Jobs\TrainSource;
use App\Models\Bot;
use App\Models\QaPair;
use App\Models\Source;
use Illuminate\Support\Facades\DB;

class SaveQaPair
{
    public function create(Bot $bot, string $question, string $answer): QaPair
    {
        $pair = DB::transaction(function () use ($bot, $question, $answer) {
            $source = Source::create([
                'bot_id' => $bot->id,
                'type' => 'qa',
                'title' => $question,
                'status' => 'pending',
            ]);

            return QaPair::create([
                'bot_id' => $bot->id,
                'source_id' => $source->id,
                'question' => $question,
                'answer' => $answer,
            ]);
        });

        TrainSource::dispatch($pair->source_id, $pair->client_id);

        return $pair;
    }

    public function update(QaPair $pair, string $question, string $answer): QaPair
    {
        $pair->update(['question' => $question, 'answer' => $answer]);

        Source::whereKey($pair->source_id)->update(['status' => 'pending', 'title' => $question]);

        TrainSource::dispatch($pair->source_id, $pair->client_id);

        return $pair;
    }

    /** The model removes its source and chunk when it is deleted. */
    public function delete(QaPair $pair): void
    {
        $pair->delete();
    }
}
