<?php

namespace App\Jobs;

use App\Models\Source;
use App\Support\CurrentClient;
use App\Training\SourceTrainer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class TrainSource implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(public int $sourceId, public string $clientId)
    {
    }

    public function handle(CurrentClient $currentClient, SourceTrainer $trainer): void
    {
        // Everything inside runs as this one client: the source is only found
        // if it belongs to them, and new chunks are stamped with their id.
        $currentClient->runAs($this->clientId, function () use ($trainer) {
            $source = Source::find($this->sourceId);

            if ($source !== null) {
                $trainer->train($source);
            }
        });
    }
}
