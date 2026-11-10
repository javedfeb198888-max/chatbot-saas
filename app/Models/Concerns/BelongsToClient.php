<?php

namespace App\Models\Concerns;

use App\Models\Client;
use App\Models\Scopes\ClientScope;
use App\Support\CurrentClient;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Every model that holds client data uses this trait.
 * It filters reads to the current client and stamps client_id on new rows.
 */
trait BelongsToClient
{
    public static function bootBelongsToClient(): void
    {
        static::addGlobalScope(new ClientScope);

        static::creating(function ($model) {
            if ($model->client_id === null) {
                $model->client_id = app(CurrentClient::class)->id();
            }

            if ($model->client_id === null) {
                throw new LogicException(
                    class_basename($model).' cannot be created without a client.'
                );
            }
        });
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
