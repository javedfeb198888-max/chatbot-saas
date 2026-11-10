<?php

namespace App\Actions;

use App\Models\User;
use App\Notifications\ClientInvite;
use Illuminate\Support\Facades\Password;

class SendInvite
{
    /** Emails the user a link to set their own password. */
    public function handle(User $user): void
    {
        $token = Password::broker()->createToken($user);

        $user->notify(new ClientInvite($token));
    }
}
