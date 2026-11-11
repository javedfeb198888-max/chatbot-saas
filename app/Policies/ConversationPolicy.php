<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;

/** Created by the bot, never by hand. Everyone in the client can read them. */
class ConversationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->client_id !== null;
    }

    public function view(User $user, Conversation $conversation): bool
    {
        return $user->client_id === $conversation->client_id;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Conversation $conversation): bool
    {
        return false;
    }

    public function delete(User $user, Conversation $conversation): bool
    {
        return $user->isOwner() && $user->client_id === $conversation->client_id;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
