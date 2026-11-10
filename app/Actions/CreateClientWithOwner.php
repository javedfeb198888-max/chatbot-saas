<?php

namespace App\Actions;

use App\Enums\Role;
use App\Models\Bot;
use App\Models\Client;
use App\Models\User;
use App\Support\CurrentClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateClientWithOwner
{
    public function __construct(
        private CurrentClient $currentClient,
        private SendInvite $sendInvite,
    ) {
    }

    /**
     * Creates the client, its owner login and its first bot in one step,
     * then emails the owner an invite.
     *
     * @param  array{name: string, slug: string, status?: string, monthly_message_limit?: int}  $clientData
     */
    public function handle(array $clientData, string $ownerName, string $ownerEmail): Client
    {
        [$client, $owner] = DB::transaction(function () use ($clientData, $ownerName, $ownerEmail) {
            $client = Client::create($clientData);

            $owner = new User([
                'name' => $ownerName,
                'email' => $ownerEmail,
                'password' => Str::random(40), // replaced when the owner accepts the invite
            ]);
            $owner->client_id = $client->id;
            $owner->role = Role::Owner;
            $owner->save();

            $this->currentClient->runAs($client, fn () => Bot::create([
                'name' => $client->name.' Assistant',
                'greeting' => 'Hi! How can I help you today?',
            ]));

            return [$client, $owner];
        });

        $this->sendInvite->handle($owner);

        return $client;
    }
}
