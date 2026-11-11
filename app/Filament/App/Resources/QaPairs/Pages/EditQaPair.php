<?php

namespace App\Filament\App\Resources\QaPairs\Pages;

use App\Actions\Training\SaveQaPair;
use App\Filament\App\Resources\QaPairs\QaPairResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditQaPair extends EditRecord
{
    protected static string $resource = QaPairResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return app(SaveQaPair::class)->update($record, $data['question'], $data['answer']);
    }

    protected function getRedirectUrl(): string
    {
        return QaPairResource::getUrl('index');
    }
}
