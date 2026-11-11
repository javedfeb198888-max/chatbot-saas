<?php

namespace App\Filament\App\Resources\QaPairs\Pages;

use App\Actions\Training\SaveQaPair;
use App\Filament\App\Resources\QaPairs\QaPairResource;
use App\Models\Bot;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateQaPair extends CreateRecord
{
    protected static string $resource = QaPairResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(SaveQaPair::class)->create(Bot::firstOrFail(), $data['question'], $data['answer']);
    }

    protected function getRedirectUrl(): string
    {
        return QaPairResource::getUrl('index');
    }
}
