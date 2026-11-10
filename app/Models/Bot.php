<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClient;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

#[Fillable([
    'name', 'goal', 'system_prompt', 'greeting', 'primary_color',
    'position', 'allowed_domains', 'model', 'is_active',
])]
class Bot extends Model
{
    use BelongsToClient, HasFactory, HasUuids;

    protected static function booted(): void
    {
        static::creating(function (Bot $bot) {
            $bot->public_key ??= 'bot_'.Str::random(32);
        });
    }

    protected function casts(): array
    {
        return [
            'allowed_domains' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function sources(): HasMany
    {
        return $this->hasMany(Source::class);
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(Chunk::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function calendarConnection(): HasOne
    {
        return $this->hasOne(CalendarConnection::class);
    }
}
