<?php

namespace App\Filament\App\Resources\TeamMembers\Pages;

use App\Actions\SendInvite;
use App\Enums\Role;
use App\Filament\App\Resources\TeamMembers\TeamMemberResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CreateTeamMember extends CreateRecord
{
    protected static string $resource = TeamMemberResource::class;

    protected static bool $canCreateAnother = false;

    protected function handleRecordCreation(array $data): Model
    {
        $member = new User([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Str::random(40), // replaced when they accept the invite
        ]);

        // The client always comes from the signed-in owner, never from the form.
        $member->client_id = auth()->user()->client_id;
        $member->role = $data['role'] === Role::Owner->value ? Role::Owner : Role::Member;
        $member->save();

        app(SendInvite::class)->handle($member);

        return $member;
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Invite sent';
    }

    protected function getRedirectUrl(): string
    {
        return TeamMemberResource::getUrl('index');
    }
}
