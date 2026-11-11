<?php

namespace App\Filament\Admin\Resources\Clients\Pages;

use App\Actions\CreateClientWithOwner;
use App\Filament\Admin\Resources\Clients\ClientResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class CreateClient extends CreateRecord
{
    protected static string $resource = ClientResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(CreateClientWithOwner::class)->handle(
            Arr::only($data, ['name', 'slug', 'status', 'timezone', 'monthly_message_limit']),
            $data['owner_name'],
            $data['owner_email'],
        );
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Client created and invite sent';
    }
}
