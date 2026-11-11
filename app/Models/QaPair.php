<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClient;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['bot_id', 'source_id', 'question', 'answer'])]
class QaPair extends Model
{
    use BelongsToClient;

    protected static function booted(): void
    {
        // The pair, its source and its chunk always live and die together.
        static::deleting(function (QaPair $pair) {
            Source::whereKey($pair->source_id)->delete();
        });
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }
}
