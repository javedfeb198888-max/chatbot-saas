<?php

namespace App\Policies;

use App\Models\Booking;
use App\Models\User;

/** Created by the bot, never by hand. Everyone in the client can read them. */
class BookingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->client_id !== null;
    }

    public function view(User $user, Booking $booking): bool
    {
        return $user->client_id === $booking->client_id;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Booking $booking): bool
    {
        return false;
    }

    public function delete(User $user, Booking $booking): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
