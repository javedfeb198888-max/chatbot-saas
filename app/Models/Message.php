<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClient;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['conversation_id', 'role', 'content', 'tokens_in', 'tokens_out'])]
class Message extends Model
{
    use BelongsToClient;

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
