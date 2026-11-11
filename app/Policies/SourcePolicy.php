<?php

namespace App\Policies;

use App\Models\Source;
use App\Models\User;

/** Everyone in a client can see its training content; only owners change it. */
class SourcePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->client_id !== null;
    }

    public function view(User $user, Source $source): bool
    {
        return $user->client_id === $source->client_id;
    }

    public function create(User $user): bool
    {
        return $user->isOwner() && $user->client_id !== null;
    }

    public function update(User $user, Source $source): bool
    {
        return $user->isOwner() && $user->client_id === $source->client_id;
    }

    public function delete(User $user, Source $source): bool
    {
        return $this->update($user, $source);
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
