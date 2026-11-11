<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'slug', 'status', 'timezone', 'monthly_message_limit'])]
class Client extends Model
{
    use HasFactory, HasUuids;

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** Visitor messages answered so far in the current calendar month. */
    public function messagesThisMonth(): int
    {
        return (int) UsageDaily::withoutGlobalScopes()
            ->where('client_id', $this->id)
            ->where('day', '>=', now()->startOfMonth()->toDateString())
            ->sum('messages');
    }

    public function hasReachedMessageLimit(): bool
    {
        return $this->messagesThisMonth() >= $this->monthly_message_limit;
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function bots(): HasMany
    {
        return $this->hasMany(Bot::class);
    }
}
