<?php

namespace App\Models;

use App\Casts\Vector;
use App\Models\Concerns\BelongsToClient;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['bot_id', 'source_id', 'content', 'embedding', 'token_count', 'metadata'])]
class Chunk extends Model
{
    use BelongsToClient;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'embedding' => Vector::class,
            'metadata' => 'array',
        ];
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }
}
