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

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }
}
