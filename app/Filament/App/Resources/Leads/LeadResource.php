<?php

namespace App\Filament\App\Resources\Leads;

use App\Filament\App\Resources\Conversations\ConversationResource;
use App\Filament\App\Resources\Leads\Pages\ListLeads;
use App\Models\Lead;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class LeadResource extends Resource
{
    protected static ?string $model = Lead::class;

    protected static ?string $slug = 'leads';

    protected static string|UnitEnum|null $navigationGroup = 'Inbox';

    protected static ?int $navigationSort = 2;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserPlus;

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('name')->searchable()->placeholder('Not given'),
                TextColumn::make('email')->searchable()->copyable()->placeholder('Not given'),
                TextColumn::make('phone')->copyable()->placeholder('Not given'),
                TextColumn::make('notes')->limit(60)->wrap()->placeholder('None'),
                TextColumn::make('created_at')->label('Received')->dateTime('j M Y, H:i')->sortable(),
            ])
            ->recordActions([
                Action::make('conversation')
                    ->label('Open chat')
                    ->icon(Heroicon::OutlinedChatBubbleLeftEllipsis)
                    ->visible(fn (Lead $record) => $record->conversation_id !== null)
                    ->url(fn (Lead $record) => ConversationResource::getUrl('view', ['record' => $record->conversation_id])),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLeads::route('/'),
        ];
    }
}
