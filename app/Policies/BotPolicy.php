<?php

namespace App\Policies;

use App\Models\Bot;
use App\Models\User;

/**
 * Everyone in a client can see its bot; only owners change it.
 * V1: each client has one bot, created together with the client.
 */
class BotPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->client_id !== null;
    }

    public function view(User $user, Bot $bot): bool
    {
        return $user->client_id === $bot->client_id;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Bot $bot): bool
    {
        return $user->isOwner() && $user->client_id === $bot->client_id;
    }

    public function delete(User $user, Bot $bot): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
