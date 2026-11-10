<?php

namespace App\Models\Scopes;

use App\Support\CurrentClient;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class ClientScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(CurrentClient::class);

        if ($context->id() !== null) {
            $builder->where($model->qualifyColumn('client_id'), $context->id());

            return;
        }

        if ($context->isUnrestricted()) {
            return;
        }

        // No client and no bypass: return nothing rather than everything.
        $builder->whereRaw('1 = 0');
    }
}
