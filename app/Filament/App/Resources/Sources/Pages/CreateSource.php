<?php

namespace App\Filament\App\Resources\Sources\Pages;

use App\Actions\Training\AddSource;
use App\Filament\App\Resources\Sources\SourceResource;
use App\Models\Bot;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateSource extends CreateRecord
{
    protected static string $resource = SourceResource::class;

    protected static ?string $title = 'Add training content';

    protected static bool $canCreateAnother = false;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            // Bot is client-scoped, so this is always the signed-in client's bot.
            return app(AddSource::class)->handle(Bot::firstOrFail(), $data);
        } catch (ValidationException $e) {
            // Point the message at the form field (form state lives under "data").
            throw ValidationException::withMessages(
                collect($e->errors())->mapWithKeys(fn ($messages, $key) => ["data.{$key}" => $messages])->all()
            );
        }
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Added. Training has started.';
    }

    protected function getRedirectUrl(): string
    {
        return SourceResource::getUrl('index');
    }
}
