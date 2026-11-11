<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClient;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['bot_id', 'visitor_id', 'page_url', 'visitor_timezone', 'last_message_at'])]
class Conversation extends Model
{
    use BelongsToClient, HasUuids;

    protected function casts(): array
    {
        return ['last_message_at' => 'datetime'];
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /** The visible transcript, oldest first. */
    public function transcript(): HasMany
    {
        return $this->hasMany(Message::class)->whereIn('role', ['user', 'assistant'])->oldest('id');
    }

    public function lead(): HasOne
    {
        return $this->hasOne(Lead::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }
}
