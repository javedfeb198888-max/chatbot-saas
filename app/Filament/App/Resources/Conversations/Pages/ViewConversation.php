<?php

namespace App\Filament\App\Resources\Conversations\Pages;

use App\Filament\App\Resources\Conversations\ConversationResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\ViewRecord;

class ViewConversation extends ViewRecord
{
    protected static string $resource = ConversationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // For when a visitor asks for their data to be removed.
            DeleteAction::make(),
        ];
    }
}
