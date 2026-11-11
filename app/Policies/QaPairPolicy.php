<?php

namespace App\Policies;

use App\Models\QaPair;
use App\Models\User;

/** Everyone in a client can see its training content; only owners change it. */
class QaPairPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->client_id !== null;
    }

    public function view(User $user, QaPair $pair): bool
    {
        return $user->client_id === $pair->client_id;
    }

    public function create(User $user): bool
    {
        return $user->isOwner() && $user->client_id !== null;
    }

    public function update(User $user, QaPair $pair): bool
    {
        return $user->isOwner() && $user->client_id === $pair->client_id;
    }

    public function delete(User $user, QaPair $pair): bool
    {
        return $this->update($user, $pair);
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
