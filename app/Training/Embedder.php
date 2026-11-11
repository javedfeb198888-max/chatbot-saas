<?php

namespace App\Training;

use Laravel\Ai\Embeddings;

class Embedder
{
    /**
     * @param  array<int, string>  $texts
     * @return array<int, array<int, float>> one vector per text, same order
     */
    public function embed(array $texts): array
    {
        $config = config('chatbot.embeddings');
        $vectors = [];

        foreach (array_chunk(array_values($texts), $config['batch_size']) as $batch) {
            $response = Embeddings::for($batch)
                ->dimensions($config['dimensions'])
                ->generate($config['provider'], $config['model']);

            array_push($vectors, ...$response->embeddings);
        }

        return $vectors;
    }
}
