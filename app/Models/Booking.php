<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClient;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['bot_id', 'conversation_id', 'lead_id', 'provider_booking_uid', 'starts_at', 'status', 'raw'])]
class Booking extends Model
{
    use BelongsToClient;

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'raw' => 'array',
        ];
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }
}
