<?php

namespace App\Policies;

use App\Models\User;

/** Team management: owners manage the people inside their own client. */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOwner() && $user->client_id !== null;
    }

    public function view(User $user, User $member): bool
    {
        return $this->viewAny($user) && $user->client_id === $member->client_id;
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, User $member): bool
    {
        return false;
    }

    /** An owner can remove anyone in their client except themselves. */
    public function delete(User $user, User $member): bool
    {
        return $this->view($user, $member) && ! $member->is($user);
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
