<?php

namespace App\Policies;

use App\Models\Lead;
use App\Models\User;

/** Created by the bot, never by hand. Everyone in the client can read them. */
class LeadPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->client_id !== null;
    }

    public function view(User $user, Lead $lead): bool
    {
        return $user->client_id === $lead->client_id;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Lead $lead): bool
    {
        return false;
    }

    public function delete(User $user, Lead $lead): bool
    {
        return $user->isOwner() && $user->client_id === $lead->client_id;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
