<?php

namespace App\Filament\App\Resources\QaPairs\Pages;

use App\Filament\App\Resources\QaPairs\QaPairResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListQaPairs extends ListRecords
{
    protected static string $resource = QaPairResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Add question'),
        ];
    }
}
