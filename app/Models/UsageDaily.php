<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClient;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['day', 'messages', 'tokens_in', 'tokens_out', 'cost_usd'])]
class UsageDaily extends Model
{
    use BelongsToClient;

    protected $table = 'usage_daily';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['day' => 'date'];
    }
}
